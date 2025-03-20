<?php

namespace App\Http\Controllers;

use Amrachraf6699\LaravelGeminiAi\Facades\GeminiAi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Smalot\PdfParser\Parser;
class JobSearchController extends Controller
{
    public function searchJobs(Request $request)
    {
        $query = $request->input('query', 'developer jobs in jordan');
        $page = $request->input('page', 1);
        $numPages = $request->input('num_pages', 1);
        $country = $request->input('country', 'jo');
        $datePosted = $request->input('date_posted', 'all');

        try
        {
            $response = Http::withHeaders(['x-rapidapi-host' => 'jsearch.p.rapidapi.com', 'x-rapidapi-key' => env('RAPIDAPI_KEY', ''), // Replace with your actual API key
            ])->get('https://jsearch.p.rapidapi.com/search', ['query' => $query, 'page' => $page, 'num_pages' => $numPages, 'country' => $country, 'date_posted' => $datePosted,]);

            if($response->successful())
            {
                return response()->json($response->json());
            } else
            {
                return response()->json(['error' => 'API request failed'], $response->status());
            }
        } catch(\Exception $e)
        {
            return response()->json(['error' => 'An error occurred: '.$e->getMessage()], 500);
        }
    }

    public function search(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'cv' => 'required|file|mimes:pdf|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Process CV
            $path = $request->file('cv')->store('temp');
            $pdf = (new Parser())->parseFile(Storage::path($path));
            $cvContent = substr($pdf->getText(), 0, 10000); // Truncate to 10k chars
            Storage::delete($path);

            // Fetch jobs
            $jsearchResponse = Http::withHeaders([
                'x-rapidapi-host' => 'jsearch.p.rapidapi.com',
                'x-rapidapi-key' => env('RAPIDAPI_KEY'),
            ])->get('https://jsearch.p.rapidapi.com/search', [
                'query' => $request->input('query', 'developer jobs in usa'),
                'page' => $request->input('page', 1),
                'num_pages' => $request->input('num_pages', 1),
                'country' => $request->input('country', 'jo'),
                'date_posted' => $request->input('date_posted', 'all'),
            ]);

            if (!$jsearchResponse->successful()) {
                return response()->json([
                    'error' => 'JSearch API failed',
                    'details' => $jsearchResponse->body()
                ], $jsearchResponse->status());
            }

            $data = $jsearchResponse->json();
            $jobs = $data['data'] ?? [];

            // Cache key
            $cacheKey = 'job_match_'.md5($cvContent.json_encode($jobs));

            // Return cached results if available
            if (Cache::has($cacheKey)) {
                return response()->json(Cache::get($cacheKey));
            }

            // Batch processing attempt
            try {
                $analysis = $this->analyzeJobsBatch($cvContent, $jobs);
            } catch (\Exception $e) {
                \Log::error('Batch analysis failed: '.$e->getMessage());
                $analysis = $this->processJobsIndividually($cvContent, $jobs);
            }

            // Merge results
            $jobs = array_map(function($job) use ($analysis) {
                $jobId = $job['job_id'];
                return array_merge($job, [
                    'compatibility_score' => $analysis[$jobId]['score'] ?? 0,
                    'match_reasons' => $analysis[$jobId]['reasons'] ?? ['Analysis unavailable']
                ]);
            }, $jobs);

            // Sort results
            usort($jobs, fn($a, $b) => $b['compatibility_score'] <=> $a['compatibility_score']);

            // Prepare response
            $response = [
                'data' => $jobs,
                'analysis_metadata' => [
                    'total_jobs' => count($jobs),
                    'average_score' => collect($jobs)->avg('compatibility_score'),
                    'processing_mode' => isset($e) ? 'individual' : 'batch',
                    'timestamp' => now()->toDateTimeString()
                ]
            ];

            // Cache response
            Cache::put($cacheKey, $response, 3600); // 1 hour

            return response()->json($response);

        } catch (\Exception $e) {
            \Log::error('Search error: '.$e->getMessage());
            return response()->json([
                'error' => 'Processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function analyzeJobsBatch(string $cvContent, array $jobs): array
    {
        $batchLimit = 50; // Max jobs per batch
        $batchJobs = array_slice($jobs, 0, $batchLimit);

        // Prepare job descriptions
        $jobDescriptions = [];
        foreach ($batchJobs as $job) {
            $jobDescriptions[$job['job_id'] = substr($job['job_description'] ?? '', 0, 1000)];
        }

        // Build prompt
        $prompt = <<<PROMPT
    Analyze job compatibility between this CV and multiple positions. Return JSON with:
    {
        "jobs": {
            "job_id1": { "score": 0-100, "reasons": ["reason1", "reason2"] },
            "job_id2": { ... }
        }
    }

    CV:
    {$cvContent}

    Jobs:
    ###
    PROMPT;

        foreach ($jobDescriptions as $id => $desc) {
            $prompt .= "\n{$id}:\n{$desc}\n---\n";
        }

        // API call
        $response = GeminiAi::generateText($prompt, [
            'model' => 'gemini-1.5-pro',
            'temperature' => 0.2,
            'maxOutputTokens' => 4000
        ]);

        // Parse response
        preg_match('/\{"jobs":\s*\{.*?\}\}/s', $response['text'], $matches);
        $result = json_decode($matches[0] ?? '{}', true);

        return $result['jobs'] ?? [];
    }

    private function processJobsIndividually(string $cvContent, array $jobs): array
    {
        $rateLimiter = RateLimiter::perMinute(120);
        $analysis = [];

        foreach ($jobs as $job) {
            try {
                if (!$rateLimiter->attempt()) {
                    $analysis[$job['job_id']] = [
                        'score' => 0,
                        'reasons' => ['Rate limit exceeded']
                    ];
                    continue;
                }

                $response = GeminiAi::generateText(
                    $this->individualPrompt($cvContent, $job),
                    ['model' => 'gemini-pro', 'maxOutputTokens' => 1000]
                );

                preg_match('/\{"score":\s*\d+,?\s*"reasons":\s*\[.*?\]\}/s', $response['text'], $match);
                $analysis[$job['job_id']] = json_decode($match[0] ?? '{"score":0}', true);

            } catch (\Exception $e) {
                \Log::warning("Individual analysis failed for {$job['job_id']}: ".$e->getMessage());
                $analysis[$job['job_id']] = ['score' => 0, 'reasons' => ['Analysis error']];
            }
        }

        return $analysis;
    }

    private function individualPrompt(string $cvContent, array $job): string
    {
        $truncatedCV = substr($cvContent, 0, 5000);
        $truncatedJob = substr($job['job_description'] ?? '', 0, 2000);

        return <<<PROMPT
    Analyze compatibility between this CV and job. Return JSON:
    {
        "score": 0-100,
        "reasons": ["short match reason 1", "reason 2"]
    }

    CV:
    {$truncatedCV}

    Job:
    {$truncatedJob}
    PROMPT;
    }
}

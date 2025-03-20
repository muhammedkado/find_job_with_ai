<?php

namespace App\Http\Controllers;

use Amrachraf6699\LaravelGeminiAi\Facades\GeminiAi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
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
        // Validate PDF file
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
            // Process CV file
            $path = $request->file('cv')->store('temp');
            $pdf = (new Parser())->parseFile(Storage::path($path));
            $cvContent = substr($pdf->getText(), 0, 10000); // Limit to 10k characters
            Storage::delete($path);

            // Fetch jobs from JSearch API
            $jsearchResponse = Http::withHeaders([
                'x-rapidapi-host' => 'jsearch.p.rapidapi.com',
                'x-rapidapi-key' => env('RAPIDAPI_KEY'),
            ])->get('https://jsearch.p.rapidapi.com/search', [
                'query' => $request->input('query', 'developer jobs in usa'),
                'page' => $request->input('page', 1),
                'num_pages' => $request->input('num_pages', 1),
                'country' => $request->input('country', 'us'),
                'date_posted' => $request->input('date_posted', 'all'),
            ]);

            if (!$jsearchResponse->successful()) {
                return response()->json([
                    'error' => 'Failed to fetch jobs',
                    'details' => $jsearchResponse->body()
                ], $jsearchResponse->status());
            }

            $jobs = $jsearchResponse->json()['data'] ?? [];

            // Extract job descriptions
            $jobDescriptions = [];
            foreach ($jobs as $job) {
                $jobDescriptions[] = [
                    'id' => $job['job_id'],
                    'text' => substr($job['job_description'] ?? '', 0, 2000) // Limit to 2000 chars
                ];
            }

            // Create analysis prompt
            $prompt = $this->createAnalysisPrompt($cvContent, $jobDescriptions);

            // Get Gemini analysis
            $geminiResponse = GeminiAi::generateText($prompt, [
                'model' => 'gemini-1.5-pro',
                'temperature' => 0.2,
                'maxOutputTokens' => 4000
            ]);

            $responseText = is_array($geminiResponse)
                ? ($geminiResponse['text'] ?? json_encode($geminiResponse))
                : (string)$geminiResponse;
            // Parse compatibility scores
            $compatibilityScores = $this->parseGeminiResponse($responseText);

            // Merge scores with job data
            $processedJobs = array_map(function($job) use ($compatibilityScores) {
                $jobId = $job['job_id'];
                $scoreData = $compatibilityScores[$jobId] ?? ['score' => 0, 'reasons' => []];
                return [
                    'job_id' => $jobId,
                    'job_title' => $job['job_title'],
                    'job_description' => $job['job_description'],
                    'job_posted_at' => $job['job_posted_at'],
                    'job_location' => $job['job_location'],
                    'job_publisher' => $job['job_publisher'],
                    'job_apply_link' => $job['job_apply_link'],
                    'job_employment_type' => $job['job_employment_type'],
                    'employer_logo' => $job['employer_logo'],
                    'employer_name' => $job['employer_name'],
                    'compatibility' => (int)($scoreData['score'] ?? 0),
                    'match_reasons' => $scoreData['reasons']
                ];
            }, $jobs);

            // Sort by compatibility score
            usort($processedJobs, fn($a, $b) => $b['compatibility'] <=> $a['compatibility']);
            $averageScore = collect($processedJobs)->avg('compatibility');
            $averageScore = round($averageScore, 1); // Round to 1 decimal place
            return response()->json([
                'jobs' => $processedJobs,
                'meta' => [
                    'total_jobs' => count($processedJobs),
                    'average_score' => $averageScore,
                    'timestamp' => now()->toDateTimeString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function createAnalysisPrompt(string $cvContent, array $jobDescriptions): string
    {
        $prompt = "Analyze CV compatibility with these job descriptions. Return JSON format:\n";
        $prompt .= json_encode(['jobs' => [
            'JOB_ID' => [
                'score' => '0-100',
                'reasons' => ['array of matching skills/requirements']
            ]
        ]]);

        $prompt .= "\n\nCV CONTENT:\n" . $cvContent . "\n\nJOB DESCRIPTIONS:\n";

        foreach ($jobDescriptions as $desc) {
            $prompt .= "--- JOB ID: {$desc['id']} ---\n{$desc['text']}\n\n";
        }

        $prompt .= "\nReturn ONLY valid JSON. Focus on project and technical skills matching.";

        return $prompt;
    }

    private function parseGeminiResponse(string $responseText): array
    {
        // First try to parse the entire response as JSON
        $result = json_decode($responseText, true);

        // If that fails, try to extract JSON from the response
        if (json_last_error() !== JSON_ERROR_NONE) {
            preg_match('/\{(?:[^{}]|(?R))*\}/s', $responseText, $matches);
            $result = json_decode($matches[0] ?? '{}', true);
        }

        // Convert string scores to integers and validate structure
        $compatibilityScores = [];
        foreach ($result['jobs'] ?? [] as $jobId => $jobData) {
            $compatibilityScores[$jobId] = [
                'score' => intval($jobData['score'] ?? 0),
                'reasons' => is_array($jobData['reasons'] ?? null)
                    ? $jobData['reasons']
                    : ['No analysis provided']
            ];
        }

        return $compatibilityScores;
    }
}

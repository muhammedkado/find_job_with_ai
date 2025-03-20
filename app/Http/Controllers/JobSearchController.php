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
        $query = $request->input('position', 'developer jobs in jordan');
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

    public function jobs(Request $request)
    {
        // Validate request parameters
    $validator = Validator::make($request->all(), [
        'skills' => 'required|string|max:5000',
        'projects' => 'nullable|array',
        'projects.*.description' => 'nullable|string|max:10000',
        'experience' => 'nullable|array',
        'experience.*.description' => 'nullable|string|max:10000',
        'summary' => 'nullable|string|max:10000',
        'position' => 'nullable|string',
        'contact' => 'nullable|array',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid request parameters.',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // Prepare candidate information
        $candidateInfo = "Skills:\n" . mb_convert_encoding(
            substr($request->input('skills'), 0, 5000),
            'UTF-8',
            'UTF-8'
        );

        // Process projects
        if ($request->has('projects')) {
            $projectDescriptions = collect($request->input('projects'))
                ->pluck('description')
                ->implode("\n");
            $candidateInfo .= "\n\nProject Experience:\n" . mb_convert_encoding(
                substr($projectDescriptions, 0, 10000),
                'UTF-8',
                'UTF-8'
            );
        }

        // Process experience
        if ($request->has('experience')) {
            $experienceDescriptions = collect($request->input('experience'))
                ->pluck('description')
                ->implode("\n");
            $candidateInfo .= "\n\nProfessional Experience:\n" . mb_convert_encoding(
                substr($experienceDescriptions, 0, 10000),
                'UTF-8',
                'UTF-8'
            );
        }

        if ($request->has('summary')) {
            $candidateInfo .= "\n\nSummary:\n" . mb_convert_encoding(
                substr($request->input('summary'), 0, 10000),
                'UTF-8',
                'UTF-8'
            );
        }

            // Fetch jobs from JSearch API
            $jsearchResponse = Http::withHeaders([
                'x-rapidapi-host' => 'jsearch.p.rapidapi.com',
                'x-rapidapi-key' => env('RAPIDAPI_KEY'),
            ])->get('https://jsearch.p.rapidapi.com/search', [
                'query' => $request->input('position', 'developer jobs in usa'),
                'page' => $request->input('page', 1),
                'num_pages' => $request->input('num_pages', 2),
                'country' => strtolower($request->input('country', 'us')),
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
            $prompt = $this->createAnalysisPrompt($candidateInfo, $jobDescriptions);

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
            $averageScore = round($averageScore, 1);

            return response()->json([
                'jobs' => $processedJobs,
                'meta' => [
                    'total_jobs' => count($processedJobs),
                    'average_score' => $averageScore,
                    'timestamp' => now()->toDateTimeString()
                ]
            ], 200, [
                JSON_UNESCAPED_UNICODE,
                JSON_INVALID_UTF8_SUBSTITUTE,
                JSON_PARTIAL_OUTPUT_ON_ERROR
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function createAnalysisPrompt(string $candidateInfo, array $jobDescriptions): string
    {
        $prompt = <<<PROMPT
**Task**: Analyze job compatibility based on:
- Candidate's technical skills
- Project experience
- Professional Experience
- Career summary
- Job requirements

**Response Format**: STRICTLY VALID JSON
{
  "jobs": {
    "JOB_ID": {
      "score": 0-100,
    }
  }
}

**Candidate Profile**:
{$candidateInfo}
**Job Analysis Instructions**:
1. For each job, identify 3-5 key match reasons
2. Score based on technical requirements matching
3. Prioritize specific technologies over generic terms
4. Consider years of experience where mentioned
5. Match both explicit and implicit requirements

**Job Descriptions**:
PROMPT;

        foreach ($jobDescriptions as $desc) {
            $prompt .= "\n\n--- JOB ID: {$desc['id']} ---\n".trim($desc['text']);
        }

        $prompt .= "\n\n**Important Notes**:\n";
        $prompt .= "- Return ONLY valid JSON (no markdown)\n";
        $prompt .= "- Ensure proper JSON escaping\n";
        $prompt .= "- Array items should be specific technical matches\n";
        $prompt .= "- If no matches exist, score 0 with empty reasons\n";
        $prompt .= "- Ignore generic requirements like 'team player'\n";
        $prompt .= "- Prioritize matches in: ";
        $prompt .= implode(', ', ['technologies', 'frameworks', 'specific tools', 'certifications']);

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

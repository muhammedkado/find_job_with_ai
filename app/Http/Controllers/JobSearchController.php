<?php

namespace App\Http\Controllers;

use Amrachraf6699\LaravelGeminiAi\Facades\GeminiAi;
use App\Services\DemoBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class JobSearchController extends Controller
{
    public function searchJobs(Request $request)
    {
        $query = $request->input('position', 'developer jobs');
        $page = $request->input('page', 1);
        $numPages = $request->input('num_pages', 1);
        $country = $request->input('country', 'us');
        $datePosted = $request->input('date_posted', 'all');

        if (! config('services.rapidapi.key') || ! DemoBudget::consumeJsearch()) {
            return response()->json($this->sampleJobsResponse());
        }

        $cacheKey = 'jsearch:' . md5($query . $page . $numPages . $country . $datePosted);

        try {
            $data = Cache::remember($cacheKey, now()->addHours(24), function () use ($query, $page, $numPages, $country, $datePosted) {
                $response = Http::withHeaders([
                    'x-rapidapi-host' => 'jsearch.p.rapidapi.com',
                    'x-rapidapi-key' => config('services.rapidapi.key'),
                ])->timeout(20)->get('https://jsearch.p.rapidapi.com/search', [
                    'query' => $query,
                    'page' => $page,
                    'num_pages' => $numPages,
                    'country' => $country,
                    'date_posted' => $datePosted,
                ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('JSearch request failed: ' . $response->status());
                }

                return $response->json();
            });

            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json($this->sampleJobsResponse());
        }
    }

    public function jobs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sample' => 'sometimes|boolean',
            'skills' => 'required_without:sample|string|max:5000',
            'projects' => 'nullable|array',
            'projects.*.description' => 'nullable|string|max:10000',
            'experience' => 'nullable|array',
            'experience.*.description' => 'nullable|string|max:10000',
            'summary' => 'nullable|string|max:10000',
            'position' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->boolean('sample') || ! config('services.rapidapi.key')) {
            return response()->json($this->sampleJobsResponse());
        }

        if (! DemoBudget::consumeJsearch()) {
            return response()->json($this->sampleJobsResponse('demo_limited'));
        }

        try {
            $candidateInfo = $this->buildCandidateSummary($request);

            $jsearchResponse = Http::withHeaders([
                'x-rapidapi-host' => 'jsearch.p.rapidapi.com',
                'x-rapidapi-key' => config('services.rapidapi.key'),
            ])->timeout(20)->get('https://jsearch.p.rapidapi.com/search', [
                'query' => $request->input('position', 'developer jobs'),
                'page' => $request->input('page', 1),
                'num_pages' => $request->input('num_pages', 1),
                'country' => strtolower((string) $request->input('country', 'us')),
                'date_posted' => $request->input('date_posted', 'all'),
            ]);

            if (! $jsearchResponse->successful()) {
                return response()->json($this->sampleJobsResponse());
            }

            $jobs = $jsearchResponse->json()['data'] ?? [];

            if (! DemoBudget::consumeGemini()) {
                return response()->json($this->sampleJobsResponse('demo_limited'));
            }

            $jobDescriptions = [];
            foreach ($jobs as $job) {
                $jobDescriptions[] = [
                    'id' => $job['job_id'],
                    'text' => substr($job['job_description'] ?? '', 0, 2000),
                ];
            }

            $prompt = $this->createAnalysisPrompt($candidateInfo, $jobDescriptions);

            $responseText = GeminiAi::generateText($prompt, [
                'model' => config('gemini.models.text'),
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 4000,
                ],
            ]);

            $compatibilityScores = $this->parseGeminiResponse($responseText);

            $processedJobs = array_map(function ($job) use ($compatibilityScores) {
                $jobId = $job['job_id'];
                $scoreData = $compatibilityScores[$jobId] ?? ['score' => 0, 'reasons' => []];

                return [
                    'job_id' => $jobId,
                    'job_title' => $job['job_title'] ?? null,
                    'job_description' => $job['job_description'] ?? null,
                    'job_posted_at' => $job['job_posted_at'] ?? null,
                    'job_location' => $job['job_location'] ?? null,
                    'job_publisher' => $job['job_publisher'] ?? null,
                    'job_apply_link' => $job['job_apply_link'] ?? null,
                    'job_employment_type' => $job['job_employment_type'] ?? null,
                    'employer_logo' => $job['employer_logo'] ?? null,
                    'employer_name' => $job['employer_name'] ?? null,
                    'compatibility' => (int) ($scoreData['score'] ?? 0),
                    'match_reasons' => $scoreData['reasons'],
                ];
            }, $jobs);

            usort($processedJobs, fn ($a, $b) => $b['compatibility'] <=> $a['compatibility']);
            $averageScore = round(collect($processedJobs)->avg('compatibility') ?? 0, 1);

            return response()->json([
                'jobs' => $processedJobs,
                'meta' => [
                    'sample' => false,
                    'total_jobs' => count($processedJobs),
                    'average_score' => $averageScore,
                    'timestamp' => now()->toDateTimeString(),
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (\Throwable $e) {
            return response()->json($this->sampleJobsResponse());
        }
    }

    private function buildCandidateSummary(Request $request): string
    {
        $candidateInfo = "Skills:\n" . mb_convert_encoding(substr((string) $request->input('skills'), 0, 5000), 'UTF-8', 'UTF-8');

        if ($request->has('projects')) {
            $descriptions = collect($request->input('projects'))->pluck('description')->implode("\n");
            $candidateInfo .= "\n\nProject Experience:\n" . mb_convert_encoding(substr($descriptions, 0, 10000), 'UTF-8', 'UTF-8');
        }

        if ($request->has('experience')) {
            $descriptions = collect($request->input('experience'))->pluck('description')->implode("\n");
            $candidateInfo .= "\n\nProfessional Experience:\n" . mb_convert_encoding(substr($descriptions, 0, 10000), 'UTF-8', 'UTF-8');
        }

        if ($request->filled('summary')) {
            $candidateInfo .= "\n\nSummary:\n" . mb_convert_encoding(substr((string) $request->input('summary'), 0, 10000), 'UTF-8', 'UTF-8');
        }

        return $candidateInfo;
    }

    private function sampleJobsResponse(?string $reason = null): array
    {
        $data = json_decode(file_get_contents(resource_path('samples/jobs.json')), true);

        if ($reason === 'demo_limited') {
            $data['meta']['demo_limited'] = true;
        }

        return $data;
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
              "reasons": ["reason 1", "reason 2"]
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
            $prompt .= "\n\n--- JOB ID: {$desc['id']} ---\n" . trim($desc['text']);
        }

        $prompt .= "\n\n**Important Notes**:\n";
        $prompt .= "- Return ONLY valid JSON (no markdown)\n";
        $prompt .= "- Ensure proper JSON escaping\n";
        $prompt .= "- Array items should be specific technical matches\n";
        $prompt .= "- If no matches exist, score 0 with empty reasons\n";
        $prompt .= "- Ignore generic requirements like 'team player'\n";
        $prompt .= '- Prioritize matches in: ' . implode(', ', ['technologies', 'frameworks', 'specific tools', 'certifications']);

        return $prompt;
    }

    private function parseGeminiResponse(string $responseText): array
    {
        $result = json_decode($responseText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            preg_match('/\{(?:[^{}]|(?R))*\}/s', $responseText, $matches);
            $result = json_decode($matches[0] ?? '{}', true);
        }

        $compatibilityScores = [];
        foreach ($result['jobs'] ?? [] as $jobId => $jobData) {
            $compatibilityScores[$jobId] = [
                'score' => intval($jobData['score'] ?? 0),
                'reasons' => is_array($jobData['reasons'] ?? null) ? $jobData['reasons'] : ['No analysis provided'],
            ];
        }

        return $compatibilityScores;
    }
}

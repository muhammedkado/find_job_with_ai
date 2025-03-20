<?php

namespace App\Http\Controllers;

use Amrachraf6699\LaravelGeminiAi\Facades\GeminiAi;
use Illuminate\Http\Request;
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

        try {
            $response = Http::withHeaders([
                'x-rapidapi-host' => 'jsearch.p.rapidapi.com',
                'x-rapidapi-key' => env('RAPIDAPI_KEY', ''), // Replace with your actual API key
            ])->get('https://jsearch.p.rapidapi.com/search', [
                'query' => $query,
                'page' => $page,
                'num_pages' => $numPages,
                'country' => $country,
                'date_posted' => $datePosted,
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            } else {
                return response()->json(['error' => 'API request failed'], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function search(Request $request)
    {
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

        $path = $request->file('cv')->store('temp');
        $query = $request->input('query', 'developer jobs in usa');
        $page = $request->input('page', 1);
        $numPages = $request->input('num_pages', 1);
        $country = $request->input('country', 'jo');
        $datePosted = $request->input('date_posted', 'all');

        try {
            $pdf = (new Parser())->parseFile(Storage::path($path));
            $cvContent = $pdf->getText();
            Storage::delete($path);

            // Get jobs from JSearch
            $response = Http::withHeaders([
                'x-rapidapi-host' => 'jsearch.p.rapidapi.com',
                'x-rapidapi-key' => env('RAPIDAPI_KEY', ''),
            ])->get('https://jsearch.p.rapidapi.com/search', [
                'query' => $query,
                'page' => $page,
                'num_pages' => $numPages,
                'country' => $country,
                'date_posted' => $datePosted,
            ]);

            if (!$response->successful()) {
                return response()->json(['error' => 'JSearch API request failed'], $response->status());
            }

            $data = $response->json();
            $jobs = $data['data'] ?? [];

            // Analyze each job with CV using GeminiAi::generateText
            foreach ($jobs as &$job) {
                $jobDescription = $job['job_description'] ?? '';

                // Prepare the prompt
                $prompt = "Analyze the compatibility between this CV and job description. Consider skills, technologies, experience, and qualifications. Return ONLY a JSON object with: { compatibility_score: number (0-100), match_reasons: string[] }.\n\nCV:\n$cvContent\n\nJob Description:\n$jobDescription";

                try {
                    $response = GeminiAi::generateText($prompt, [
                        'model' => 'gemini-1.5-pro',
                        'raw' => true,
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => 2000
                        ]
                    ]);

                    $responseText = $response['text'] ?? '';

                    // Parse Gemini response
                    $pattern = '/{.*}/s';
                    preg_match($pattern, $responseText, $matches);
                    $analysis = json_decode($matches[0] ?? '{}', true);

                    $job['compatibility'] = $analysis['compatibility_score'] ?? 0;
                    $job['match_reasons'] = $analysis['match_reasons'] ?? [];
                } catch (\Exception $e) {
                    $job['compatibility'] = 0;
                    $job['match_reasons'] = ['Analysis failed'];
                }
            }

            // Sort jobs by compatibility score
            usort($jobs, function($a, $b) {
                return $b['compatibility'] <=> $a['compatibility'];
            });

            $data['data'] = $jobs;

            return response()->json($data);

        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

}

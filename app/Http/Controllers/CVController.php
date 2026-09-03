<?php

namespace App\Http\Controllers;

use Amrachraf6699\LaravelGeminiAi\Facades\GeminiAi;
use App\Services\DemoBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Smalot\PdfParser\Parser;

class CVController extends Controller
{
    public function analyze(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cv' => 'required_without:sample|file|mimes:pdf|max:2048',
            'sample' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->boolean('sample')) {
            return response()->json([
                'success' => true,
                'message' => 'Loaded a sample CV.',
                'data' => $this->sampleProfile(),
            ]);
        }

        if (! DemoBudget::consumeGemini()) {
            return response()->json([
                'success' => true,
                'message' => "Today's demo AI budget is used up — showing a sample profile instead.",
                'data' => $this->sampleProfile(true),
            ]);
        }

        try {
            $path = $request->file('cv')->store('temp');
            $pdf = (new Parser())->parseFile(Storage::path($path));
            $cvContent = $pdf->getText();
            Storage::delete($path);

            $prompt = $this->extractionPrompt($cvContent);
            $responseText = GeminiAi::generateText($prompt, [
                'model' => config('gemini.models.text'),
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 2000,
                ],
            ]);

            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $responseText, $matches)) {
                $responseText = $matches[1];
            }

            $parsedData = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response: ' . json_last_error_msg());
            }

            $parsedData = $this->normalizeProfile($parsedData);

            return response()->json([
                'success' => true,
                'message' => 'Analysis completed successfully',
                'data' => $parsedData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? 'Analysis failed: ' . $e->getMessage()
                    : 'Failed to process CV. Please try again.',
            ], 500);
        }
    }

    public function enhance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text' => 'required|string|max:2000',
            'section' => 'sometimes|string|in:experience,project,education,summary',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $text = $request->input('text');
        $section = $request->input('section', 'general');

        if (! DemoBudget::consumeGemini()) {
            return response()->json([
                'success' => false,
                'message' => "Today's demo AI budget is used up — try again tomorrow.",
                'demo_limited' => true,
            ], 429);
        }

        try {
            $prompts = [
                'experience' => 'Rewrite this work experience description to be more professional and impactful. Focus on achievements and measurable outcomes. Keep it concise (2 lines max). Text: ',
                'project' => 'Rephrase this project description to highlight technical challenges and solutions. Use active voice and technical terminology. Keep it brief (2 lines). Text: ',
                'education' => 'Enhance this education entry to emphasize relevant coursework and accomplishments. Maintain academic formal tone. 2 lines maximum. Text: ',
                'summary' => 'Improve this professional summary to be more compelling and ATS-friendly. Focus on key qualifications and career highlights. Keep it to 2 strong lines. Text: ',
                'general' => 'Rewrite the following text to be more professional and concise while maintaining meaning. Use formal business language and keep it to two short lines. Text: ',
            ];

            $prompt = ($prompts[$section] ?? $prompts['general']) . "\n\n" . $text;

            $enhancedText = trim(GeminiAi::generateText($prompt, [
                'model' => config('gemini.models.text'),
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 100,
                ],
            ]));

            $lines = preg_split('/\n+/', $enhancedText);
            $formattedOutput = implode("\n", array_slice(array_filter(array_map('trim', $lines)), 0, 2));

            return response()->json([
                'success' => $formattedOutput !== '',
                'message' => $formattedOutput !== '' ? 'Enhancement completed successfully' : 'Could not enhance the text',
                'data' => $formattedOutput !== '' ? $formattedOutput : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? 'Enhancement Error: ' . $e->getMessage()
                    : 'Enhancement failed. Please try again.',
            ], 500);
        }
    }

    private function sampleProfile(bool $limited = false): array
    {
        $profile = json_decode(file_get_contents(resource_path('samples/profile.json')), true);
        $profile['_sample'] = true;
        $profile['_demo_limited'] = $limited;

        return $profile;
    }

    private function normalizeProfile(array $parsedData): array
    {
        $defaultStructure = [
            'name' => null,
            'birthday' => null,
            'position' => null,
            'summary' => null,
            'education' => null,
            'experience' => [
                [
                    'position' => null,
                    'company' => null,
                    'start_date' => null,
                    'end_date' => null,
                    'description' => null,
                ],
            ],
            'internships' => [],
            'projects' => [],
            'skills' => null,
            'languages' => null,
            'socialAccounts' => [
                'linkedin' => null,
                'github' => null,
            ],
        ];

        $parsedData = array_merge($defaultStructure, $parsedData);
        $parsedData['internships'] = (array) ($parsedData['internships'] ?? []);
        $parsedData['projects'] = (array) ($parsedData['projects'] ?? []);
        $parsedData['skills'] = $parsedData['skills'] ?? '';
        $parsedData['languages'] = $parsedData['languages'] ?? '';
        $parsedData['socialAccounts'] = array_merge(
            $defaultStructure['socialAccounts'],
            (array) ($parsedData['socialAccounts'] ?? [])
        );

        return $parsedData;
    }

    private function extractionPrompt(string $cvContent): string
    {
        return <<<PROMPT
        Extract CV information exactly as written, returning ONLY a valid JSON object formatted as:

        ```json
        {
            "name": "Full name as written",
            "birthday": "Birth date or null",
            "position": "Current/Main job title",
            "contact": { "email": "", "phone": "", "city": "", "country": "" },
            "summary": "Professional summary text or null",
            "education": {
                "degree": "Degree name",
                "institution": "Institution name",
                "startingYear": "Starting year",
                "graduationYear": "Graduation year"
            },
            "experience": [
                {
                    "position": "Job title verbatim",
                    "company": "Company name exactly as written",
                    "start_date": "Employment start date as specified",
                    "end_date": "Employment finish date as specified (use 'Present' if currently employed)",
                    "description": "Full job description with bullet points exactly as written"
                }
            ],
            "internships": ["Array of internship details"],
            "projects": [
                {
                    "title": "Exact project name as written",
                    "description": "Full project description verbatim",
                    "technologies": "Technologies listed exactly as shown",
                    "duration": "Project duration as specified"
                }
            ],
            "skills": "Comma-separated list of TECHNICAL SKILLS (e.g., Python, Git). Exclude category labels like 'Languages:'",
            "languages": "list of languages with proficiency separated by commas",
            "socialAccounts": {
                "linkedin": "URL or null",
                "github": "URL or null"
            }
        }
        ```

        Rules:
        - Maintain original text exactly, especially for projects
        - Preserve ALL project details verbatim
        - Include ALL job description bullet points exactly as written
        - Preserve bullet point characters (•, -, etc.) in descriptions
        - Education and Experience must be arrays even if a single entry
        - Use empty arrays if no projects/education/experience exist
        - Never modify or rephrase project information
        - Return ONLY the JSON without additional text

        CV Content:
        {$cvContent}
        PROMPT;
    }
}

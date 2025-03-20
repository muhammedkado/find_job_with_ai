<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Amrachraf6699\LaravelGeminiAi\Facades\GeminiAi;

class CVController extends Controller
{
    public function analyze(Request $request)
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

        try {
            $istest = $request->input('test');
            if ($istest) {
                $defaultStructure = [
                    'name' => 'John Doe',
                    'birthday' => '1990-01-01',
                    'job_title' => 'Software Engineer',
                    'summary' => 'Experienced software engineer with a strong background in developing scalable web applications.',
                    'education' => [
                        [
                            'degree' => 'B.Sc. Computer Science',
                            'institution' => 'University of Example',
                            'graduationYear' => '2012'
                        ]
                    ],
                    'experience' => [
                        [
                            'position' => 'Senior Software Engineer',
                            'employer' => 'Tech Company',
                            'dates' => '2015-2020',
                            'description' => 'Developed and maintained web applications using PHP and Laravel.'
                        ]
                    ],
                    'internships' => [
                        [
                            'position' => 'Software Engineering Intern',
                            'employer' => 'Startup Inc.',
                            'dates' => '2014',
                            'description' => 'Assisted in the development of a mobile application.'
                        ]
                    ],
                    'projects' => [
                        [
                            'name' => 'Project Alpha',
                            'description' => 'A web application for managing tasks and projects.',
                            'technologies' => 'PHP, Laravel, MySQL',
                            'duration' => '6 months'
                        ]
                    ],
                    'skills' => ['PHP', 'Laravel', 'JavaScript', 'MySQL'],
                    'languages' => ['English', 'Spanish'],
                    'social_media_accounts' => [
                        'linkedin' => 'https://linkedin.com/in/johndoe',
                        'github' => 'https://github.com/johndoe'
                    ]
                ];
                return response()->json([
                    'success' => true,
                    'message' => 'Test completed successfully',
                    'data' => $defaultStructure
                ]);
            } else {
            // Process PDF file
            $path = $request->file('cv')->store('temp');
            $pdf = (new Parser())->parseFile(Storage::path($path));
            $cvContent = $pdf->getText();
            Storage::delete($path);

            // Prepare structured prompt
            $prompt = <<<PROMPT
            Extract CV information exactly as written, returning ONLY a valid JSON object formatted as:

            ```json
            {
                "name": "Full name as written",
                "Birthday": "Birth date or 'Not specified'",
                "Job Title": "Current/Main job title",
                "summary": "Professional summary text or null",
                "Education": [
                    {
                        "degree": "Exact degree name",
                        "institution": "Institution name as written",
                        "graduationYear": "Year or date exactly as shown"
                    }
                ],
                "Experience": [
                     {
                        "position": "Job title verbatim",
                        "employer": "Company name exactly as written",
                        "dates": "Employment dates as specified",
                        "description": "Full job description with bullet points exactly as written"
                    }
                ],
                "Internships": ["Array of internship details"],
                "Projects": [
                    {
                        "name": "Exact project name as written",
                        "description": "Full project description verbatim",
                        "technologies": "Technologies listed exactly as shown",
                        "duration": "Project duration as specified"
                    }
                ],
                "Skills": ["Array of technical skills"],
                "Languages": ["Array of languages with proficiency"],
                "Social Media Accounts": {
                    "linkedin": "URL or null",
                    "github": "URL or null"
                }
            }
Rules:

Maintain original text exactly, especially for projects

Preserve ALL project details verbatim
- Include ALL job description bullet points exactly as written
- Preserve bullet point characters (•, -, etc.) in descriptions
- Maintain original line breaks in descriptions
Education and Experience must be arrays even if single entry
Include summary if exists, otherwise null
Project name capitalization
Punctuation in descriptions
Technology names as written
Duration formatting
Use empty arrays if no projects exist
Use empty arrays if no education/experience exists
Never modify or rephrase project information

Return ONLY the JSON without additional text
CV Content:
$cvContent
PROMPT;
            // Get Gemini response
            $response = GeminiAi::generateText($prompt, [
                'model' => 'gemini-1.5-pro',
                'raw' => true,
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 2000
                ]
            ]);

            // Validate response structure
            if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                throw new \Exception('Invalid response structure from Gemini API');
            }

            $responseText = $response['candidates'][0]['content']['parts'][0]['text'];

            // Extract JSON from markdown code block
            if (preg_match('/```json\s*([\s\S]*?)\s*```/', $responseText, $matches)) {
                $responseText = $matches[1];
            }

            // Parse and validate JSON
            $parsedData = json_decode($responseText, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response: ' . json_last_error_msg());
            }

            // Normalize data structure
            $defaultStructure = [
                'name' => null,
                'Birthday' => null,
                'job_title' => null,
                'summary' => null,
                'education' => [],
                'experience' => [
                    [
                        'position' => null,
                        'employer' => null,
                        'dates' => null,
                        'description' => null
                    ]
                ],
                'internships' => [],
                'projects' => [],
                'skills' => [],
                'languages' => [],
                'social_media_accounts' => [
                    'linkedin' => null,
                    'github' => null
                ]
            ];

            // Merge with defaults and ensure correct types
            $parsedData = array_merge($defaultStructure, $parsedData);
            $parsedData['Internships'] = (array)($parsedData['Internships'] ?? []);
            $parsedData['Projects'] = (array)($parsedData['Projects'] ?? []);
            $parsedData['Skills'] = (array)($parsedData['Skills'] ?? []);
            $parsedData['Languages'] = (array)($parsedData['Languages'] ?? []);
            $parsedData['Social Media Accounts'] = array_merge(
                $defaultStructure['Social Media Accounts'],
                (array)($parsedData['Social Media Accounts'] ?? [])
            );

            return response()->json([
                'success' => true,
                'message' => 'Analysis completed successfully',
                'data' => $parsedData
            ]);
        }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? "Analysis failed: " . $e->getMessage()
                    : 'Failed to process CV. Please try again.',
                'errors' => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ] : null
            ], 500);
        }
    }
    public function enhance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text' => 'required|string|max:2000',
            'section' => 'sometimes|string|in:experience,project,education,summary'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $istest = $request->input('test');
            $text = $request->input('text');
            $section = $request->input('section', 'general');
            if ($istest) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test completed successfully',
                    'data' => 'This is a test response'
                ]);
            } else {
                // Context-aware enhancement prompts
                $prompts = [
                    'experience' => "Rewrite this work experience description to be more professional and impactful.
                           Focus on achievements and measurable outcomes. Keep it concise (2 lines max). Text: ",
                    'project' => "Rephrase this project description to highlight technical challenges and solutions.
                        Use active voice and technical terminology. Keep it brief (2 lines). Text: ",
                    'education' => "Enhance this education entry to emphasize relevant coursework and accomplishments.
                          Maintain academic formal tone. 2 lines maximum. Text: ",
                    'summary' => "Improve this professional summary to be more compelling and ATS-friendly.
                        Focus on key qualifications and career highlights. Keep it to 2 strong lines. Text: ",
                    'general' => "Rewrite the following text to be more professional and concise while maintaining meaning.
                        Use formal business language and keep it to two short lines. Text: "
                ];

                $prompt = $prompts[$section] ?? $prompts['general'];
                $fullPrompt = $prompt . "\n\n" . $text;

                $response = GeminiAi::generateText($fullPrompt, [
                    'model' => 'gemini-1.5-pro',
                    'raw' => true,
                    'generationConfig' => [
                        'temperature' => 0.3,  // Lower temperature for more focused output
                        'maxOutputTokens' => 100
                    ]
                ]);

                $enhancedText = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
                $enhancedText = trim($enhancedText ?? '');

                // Ensure we get exactly 2 lines
                $lines = preg_split('/\n+/', $enhancedText);
                $formattedOutput = implode("\n", array_slice(array_filter(array_map('trim', $lines)), 0, 2));
            }
            return response()->json([
                'success' => !empty($formattedOutput),
                'message' => !empty($formattedOutput)
                    ? 'Enhancement completed successfully'
                    : 'Could not enhance the text',
                'data' => !empty($formattedOutput) ? $formattedOutput : null
            ]);
        } catch (\Exception $e) {
            $errorMessage = config('app.debug')
                ? 'Enhancement Error: ' . $e->getMessage()
                : 'Enhancement failed. Please try again.';

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'errors' => config('app.debug') ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ] : null
            ], 500);
        }
    }
}

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
            $path = $request->file('cv')->store('temp');
            $pdfParser = new Parser();
            $pdf = $pdfParser->parseFile(Storage::path($path));
            $cvContent = $pdf->getText();
            Storage::delete($path);

            // ATS-optimized extraction prompt with structured output and rephrasing.
            $prompt = "Extract these fields strictly in the following format:
Full Name: <name>
Summary: <summary> (if not provided, leave blank)
Contact: Email: <email>, Phone: <phone>, City: <city>
Education: <degree> | <institution> | <startingYear> | <graduationYear> (if multiple, separate entries with a newline)
Experience: <position> | <company> | <duration> | <description> (if multiple, separate entries with a newline)
Projects: <title> | <description> (if multiple, separate entries with a newline)
Technical Skills: <comma-separated list of programming languages and technical skills>
Languages: <comma-separated list of spoken languages>
Social Media Accounts: <platform>: <url> (if multiple, separate entries with a newline)
Rephrase and clarify the extracted information to be clearer and more suitable for ATS screening, while maintaining the original content without adding any new information.
Return each field on its own line.\n" . $cvContent;

            $response = GeminiAi::generateText($prompt, [
                'model' => 'gemini-1.5-pro',
                'raw' => true,
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 1000
                ]
            ]);

            $responseText = $response['candidates'][0]['content']['parts'][0]['text'];
            $parsedData = $this->parseResponseText($responseText);

            // Separate spoken languages from programming languages.
            $parsedData = $this->separateLanguages($parsedData);

            // Generate or rephrase the summary to be ATS-optimized in first-person.
            if (empty($parsedData['summary'])) {
                // Create summary from available fields.
                $summaryData = "Full Name: " . ($parsedData['name'] ?? '') . "\n" .
                    "Contact: " . json_encode($parsedData['contact']) . "\n" .
                    "Education: " . json_encode($parsedData['education']) . "\n" .
                    "Experience: " . json_encode($parsedData['experience']) . "\n" .
                    "Projects: " . json_encode($parsedData['projects']) . "\n" .
                    "Technical Skills: " . (is_array($parsedData['technicalSkills']) ? implode(', ', $parsedData['technicalSkills']) : '') . "\n" .
                    "Languages: " . (is_array($parsedData['languages']) ? implode(', ', $parsedData['languages']) : '');

                $summaryPrompt = "Reword and refine the following summary to be clear, concise, and optimized for the Applicant Tracking System (ATS), focusing on my key role and experience, and considering my graduation date. Don't say I am currently studying if my graduation date has passed. Keep the original meaning without adding new details, and don't list all skills. Write in the first person (using \"I\" statements, not \"he\" statements). " . $summaryData;
                $summaryResponse = GeminiAi::generateText($summaryPrompt, [
                    'model' => 'gemini-1.5-pro',
                    'raw' => true,
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 150
                    ]
                ]);
                $summaryText = $summaryResponse['candidates'][0]['content']['parts'][0]['text'] ?? null;
                $parsedData['summary'] = trim($summaryText) !== '' ? $summaryText : null;
            } else {
                // Rephrase existing summary for ATS optimization.
                $summaryPrompt = "Reword and refine the following summary to be clear, concise, and optimized for the Applicant Tracking System (ATS), focusing on my key role and experience, and considering my graduation date. Don't say I am currently studying if my graduation date has passed. Keep the original meaning without adding new details, and don't list all skills. Write in the first person (using \"I\" statements, not \"he\" statements). " . $parsedData['summary'];
                $summaryResponse = GeminiAi::generateText($summaryPrompt, [
                    'model' => 'gemini-1.5-pro',
                    'raw' => true,
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 150
                    ]
                ]);
                $summaryText = $summaryResponse['candidates'][0]['content']['parts'][0]['text'] ?? null;
                $parsedData['summary'] = trim($summaryText) !== '' ? $summaryText : $parsedData['summary'];
            }

            return response()->json([
                'success' => true,
                'message' => 'Analysis completed successfully',
                'data' => $parsedData
            ]);

        } catch (\Exception $e) {
            $errorMessage = config('app.debug')
                ? 'API Error: ' . $e->getMessage()
                : 'Internal server error. Please try again later.';
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

    private function parseResponseText(string $text): array
    {
        // Initialize with the new structure.
        $result = [
            'name' => null,
            'summary' => null,
            'contact' => null,
            'education' => null,
            'experience' => null,
            'projects' => null,
            'technicalSkills' => null,
            'languages' => null,
            'socialMediaAccounts' => null
        ];

        $lines = explode("\n", trim($text));
        $currentField = null;

        foreach ($lines as $line) {
            $cleanLine = preg_replace('/^-\s*/', '', trim($line));
            if (preg_match('/^(.*?):\s*(.*)/', $cleanLine, $matches)) {
                $fieldName = strtolower(trim($matches[1]));
                $value = trim($matches[2]);
                $mappedField = $this->mapFieldName($fieldName);
                if ($mappedField && array_key_exists($mappedField, $result)) {
                    $currentField = $mappedField;
                    $result[$currentField] = $value !== '' ? $value : null;
                }
            } elseif ($currentField) {
                // Append additional lines for multi-line values.
                $result[$currentField] .= "\n" . $cleanLine;
            }
        }

        // Format the structured fields.
        $result['contact'] = $this->formatContact($result['contact']);
        $result['education'] = $this->formatEducation($result['education']);
        $result['experience'] = $this->formatExperience($result['experience']);
        $result['projects'] = $this->formatProjects($result['projects']);
        $result['technicalSkills'] = $this->formatListAsArray($result['technicalSkills']);
        $result['languages'] = $this->formatListAsArray($result['languages']);
        $result['socialMediaAccounts'] = $this->formatSocialMediaObject($result['socialMediaAccounts']);

        return $result;
    }

    private function mapFieldName(string $rawName): ?string
    {
        $fieldMap = [
            'full name' => 'name',
            'summary' => 'summary',
            'contact' => 'contact',
            'education' => 'education',
            'experience' => 'experience',
            'projects' => 'projects',
            'technical skills' => 'technicalSkills',
            'languages' => 'languages',
            'social media accounts' => 'socialMediaAccounts'
        ];

        $lower = strtolower($rawName);
        return $fieldMap[$lower] ?? null;
    }

    private function formatListAsArray(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        // Split by commas, semicolons, or newlines and filter out empty values.
        $items = preg_split('/[\n,;]+/', $input);
        $filtered = array_filter(array_map('trim', $items));
        return !empty($filtered) ? array_values($filtered) : null;
    }

    private function formatContact(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        // Expected format: "Email: <email>, Phone: <phone>, City: <city>"
        $parts = preg_split('/[,;]+/', $input);
        $contact = [];
        foreach ($parts as $part) {
            if (preg_match('/email\s*:\s*(.*)/i', $part, $matches)) {
                $contact['email'] = trim($matches[1]);
            } elseif (preg_match('/phone\s*:\s*(.*)/i', $part, $matches)) {
                $contact['phone'] = trim($matches[1]);
            } elseif (preg_match('/city\s*:\s*(.*)/i', $part, $matches)) {
                $contact['city'] = trim($matches[1]);
            }
        }
        return !empty($contact) ? $contact : null;
    }

    private function formatEducation(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        // Each education entry is expected on a new line; fields separated by "|"
        $lines = preg_split('/\n+/', $input);
        $educations = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) >= 4) {
                $educations[] = [
                    'degree' => $parts[0],
                    'institution' => $parts[1],
                    'startingYear' => $parts[2],
                    'graduationYear' => $parts[3]
                ];
            }
        }
        return !empty($educations) ? $educations : null;
    }

    private function formatExperience(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        // Each experience entry on a new line; fields separated by "|"
        $lines = preg_split('/\n+/', $input);
        $experiences = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) >= 4) {
                $experiences[] = [
                    'position' => $parts[0],
                    'company' => $parts[1],
                    'duration' => $parts[2],
                    'description' => $parts[3]
                ];
            }
        }
        return !empty($experiences) ? $experiences : null;
    }

    private function formatProjects(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        // Each project entry on a new line; fields separated by "|"
        $lines = preg_split('/\n+/', $input);
        $projects = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) >= 2) {
                $projects[] = [
                    'title' => $parts[0],
                    'description' => $parts[1]
                ];
            }
        }
        return !empty($projects) ? $projects : null;
    }

    private function formatSocialMediaObject(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        // Each social media entry on a new line; expected format: "Platform: URL"
        $lines = preg_split('/\n+/', $input);
        $socialMedia = [];
        foreach ($lines as $line) {
            if (preg_match('/^(.*?):\s*(.*)/', $line, $matches)) {
                $platform = trim($matches[1]);
                $url = trim($matches[2]);
                if ($platform && $url) {
                    $socialMedia[$platform] = $url;
                }
            }
        }
        return !empty($socialMedia) ? $socialMedia : null;
    }

    /**
     * Separates spoken languages from programming languages.
     * Spoken languages remain in the 'languages' field,
     * while any common programming languages found are added to 'technicalSkills'.
     */
    private function separateLanguages(array $result): array
    {
        $commonProgrammingLanguages = [
            'python', 'java', 'c++', 'c#', 'php', 'javascript',
            'typescript', 'ruby', 'go', 'swift', 'kotlin', 'perl',
            'r', 'scala', 'objective-c', 'html', 'css'
        ];

        if (isset($result['languages']) && is_array($result['languages'])) {
            $spoken = [];
            $programming = [];
            foreach ($result['languages'] as $lang) {
                $lower = strtolower($lang);
                if (in_array($lower, $commonProgrammingLanguages)) {
                    $programming[] = $lang;
                } else {
                    $spoken[] = $lang;
                }
            }
            $result['languages'] = !empty($spoken) ? array_values(array_unique($spoken)) : null;
            if (isset($result['technicalSkills']) && is_array($result['technicalSkills'])) {
                $merged = array_merge($result['technicalSkills'], $programming);
                $result['technicalSkills'] = array_values(array_unique($merged));
            } else if (!empty($programming)) {
                $result['technicalSkills'] = array_values(array_unique($programming));
            }
        }

        return $result;
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
            $text = $request->input('text');
            $section = $request->input('section', 'general');
    
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
            $formattedOutput = array_slice(array_filter(array_map('trim', $lines)), 0, 2);
    
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

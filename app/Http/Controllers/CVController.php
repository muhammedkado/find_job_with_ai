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

            // Updated prompt for a structured output
            $prompt = "Extract these fields strictly in the following format:
Full Name: <name>
Summary: <summary>
Contact: Email: <email>, Phone: <phone>, City: <city>
Education: <degree> | <institution> | <startingYear> | <graduationYear> (if multiple, separate entries with a newline)
Experience: <position> | <company> | <duration> | <description> (if multiple, separate entries with a newline)
Projects: <title> | <description> (if multiple, separate entries with a newline)
Technical Skills: <comma-separated list>
Languages: <comma-separated list>
Social Media Accounts: <platform>: <url> (if multiple, separate entries with a newline)
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
        // Initialize with the new structure
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
                // Append additional lines for multi-line values
                $result[$currentField] .= "\n" . $cleanLine;
            }
        }

        // Format the structured fields
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
        // Split by comma (or semicolon/newline) and filter empty values
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
        // Each education entry should be on a new line; fields are separated by "|"
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
        // Each experience entry on a new line; fields are separated by "|"
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
        // Each project entry on a new line; fields are separated by "|"
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
}

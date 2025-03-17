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

            $prompt = "Extract these fields strictly:
- Full Name: [First Middle Last]
- Birthday: [DD/MM/YYYY]
- Job Title: [Title]
- Education: [Degree, Institution, Year]
- Experience: [Position, Company, Duration]
- Internships: [Role, Company, Duration]
- Projects: [Project Name: Description]
- Technical Skills: [Comma-separated list]
- Languages: [Language (Proficiency)]
- Social Media Accounts: [Platform: URL]
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
        $result = [
            'name' => null,
            'Birthday' => null,
            'Job Title' => null,
            'Education' => null,
            'Experience' => null,
            'Internships' => null,
            'Projects' => null,
            'Technical Skills' => null,
            'Languages' => null,
            'Social Media Accounts' => null
        ];

        $lines = explode("\n", trim($text));
        $currentField = null;

        foreach ($lines as $line) {
            $cleanLine = preg_replace('/^-\s*/', '', trim($line));

            // Check for field header
            if (preg_match('/^(.*?):\s*(.*)/', $cleanLine, $matches)) {
                $fieldName = strtolower(trim($matches[1]));
                $value = trim($matches[2]);

                $mappedField = $this->mapFieldName($fieldName);
                if ($mappedField && array_key_exists($mappedField, $result)) {
                    $currentField = $mappedField;
                    $result[$currentField] = $value !== '' ? $value : null;
                }
            } elseif ($currentField) {
                // Append to current field if it's a continuation line
                $result[$currentField] .= "\n" . $cleanLine;
            }
        }

        // Cleanup and format specific fields as arrays or null if not specified
        $result['Technical Skills'] = $this->formatListAsArray($result['Technical Skills']);
        $result['Experience'] = $this->formatListAsArray($result['Experience']);
        $result['Social Media Accounts'] = $this->formatSocialMediaAsArray($result['Social Media Accounts']);
        $result['Languages'] = $this->formatListAsArray($result['Languages']);

        return $result;
    }

    private function mapFieldName(string $rawName): ?string
    {
        $fieldMap = [
            'full name' => 'name',
            'birthday' => 'Birthday',
            'job title' => 'Job Title',
            'education' => 'Education',
            'experience' => 'Experience',
            'internships' => 'Internships',
            'projects' => 'Projects',
            'technical skills' => 'Technical Skills',
            'languages' => 'Languages',
            'social media accounts' => 'Social Media Accounts'
        ];

        $lower = strtolower($rawName);
        return $fieldMap[$lower] ?? null;
    }

    private function formatList(?string $input): ?string
    {
        if ($input === null) return null;

        // Convert both comma and newline separators to a comma-separated list
        $items = preg_split('/[\n,;]+/', $input);
        $filtered = array_filter(array_map('trim', $items));
        return !empty($filtered) ? implode(', ', $filtered) : null;
    }

    private function formatListAsArray(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }

        // Convert both comma, newline, and semicolon separators to an array
        $items = preg_split('/[\n,;]+/', $input);
        $filtered = array_filter(array_map('trim', $items));
        return !empty($filtered) ? array_values($filtered) : null;
    }

    private function formatSocialMediaAsArray(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }

        // Convert to an array splitting by newlines
        $accounts = preg_split('/\n+/', $input);
        $filtered = array_filter(array_map('trim', $accounts));
        return !empty($filtered) ? array_values($filtered) : null;
    }
}

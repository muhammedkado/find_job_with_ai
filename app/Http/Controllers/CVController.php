<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Amrachraf6699\LaravelGeminiAi\Facades\GeminiAi;

class CVController extends Controller
{
    public function analyze(Request $request)
    {
        $request->validate([
            'cv' => 'required|file|mimes:pdf|max:2048',
        ]);

        $path = $request->file('cv')->store('temp');
        $pdfParser = new Parser();
        $pdf = $pdfParser->parseFile(Storage::path($path));
        $cvContent = $pdf->getText();
        Storage::delete($path);

        $prompt = "Name, birthday, job title from CV:\n" . $cvContent;
        try {
            $response = GeminiAi::generateText($prompt, [
                'model' => 'gemini-1.5-pro',
                'raw' => true,
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 1000
                ]
            ]);

            return response()->json(['analysis' => $response]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'API error: ' . $e->getMessage()], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class JobSearchController extends Controller
{
    public function searchJobs(Request $request)
    {
        $query = $request->input('query', 'developer jobs in chicago');
        $page = $request->input('page', 1);
        $numPages = $request->input('num_pages', 1);
        $country = $request->input('country', 'us');
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
}

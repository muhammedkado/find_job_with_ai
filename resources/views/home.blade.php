@extends('layouts.app')

@section('content')
<div x-data="wizard" x-cloak>

    <ol class="mb-8 flex items-center gap-3 text-sm font-medium">
        <li :class="step >= 1 ? 'text-indigo-600' : 'text-slate-400'">1. Upload</li>
        <li class="text-slate-300">&rarr;</li>
        <li :class="step >= 2 ? 'text-indigo-600' : 'text-slate-400'">2. Profile</li>
        <li class="text-slate-300">&rarr;</li>
        <li :class="step >= 3 ? 'text-indigo-600' : 'text-slate-400'">3. Matches</li>
    </ol>

    <div x-show="notice" x-text="notice" class="mb-6 rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-700"></div>
    <div x-show="error" x-text="error" class="mb-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"></div>

    {{-- STEP 1: upload --}}
    <div x-show="step === 1">
        <div class="rounded-2xl border-2 border-dashed border-slate-300 bg-white p-10 text-center">
            <h1 class="text-2xl font-bold text-slate-900">Upload your CV</h1>
            <p class="mx-auto mt-2 max-w-md text-slate-500">Drop a PDF and AI turns it into a structured profile, editable and ready to score against real job postings.</p>
            <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                <label class="cursor-pointer rounded-lg bg-indigo-600 px-5 py-2.5 font-medium text-white hover:bg-indigo-500">
                    <span x-show="!loading">Choose a PDF</span>
                    <span x-show="loading">Uploading&hellip;</span>
                    <input type="file" accept="application/pdf" class="hidden" @change="uploadFile" :disabled="loading">
                </label>
                <button @click="uploadSample" :disabled="loading" class="rounded-lg border border-slate-300 px-5 py-2.5 font-medium text-slate-700 hover:bg-slate-50">
                    Try with sample CV
                </button>
            </div>
            <p class="mt-4 text-xs text-slate-400">PDF only, up to 2MB. The file is parsed and discarded, never stored.</p>
        </div>
    </div>

    {{-- STEP 2: profile --}}
    <div x-show="step === 2" x-cloak>
        <template x-if="profile">
            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-slate-700">Name</label>
                            <input x-model="profile.name" class="mt-1 w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="text-sm font-medium text-slate-700">Position</label>
                            <input x-model="profile.position" class="mt-1 w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="flex items-center justify-between">
                            <label class="text-sm font-medium text-slate-700">Summary</label>
                            <button @click="enhance('summary', () => profile.summary, v => profile.summary = v)" :disabled="isEnhancing('summary', profile.summary)" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50">
                                <span x-show="!isEnhancing('summary', profile.summary)">&#10024; Enhance with AI</span>
                                <span x-show="isEnhancing('summary', profile.summary)">Enhancing&hellip;</span>
                            </button>
                        </div>
                        <textarea x-model="profile.summary" rows="3" class="mt-1 w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-slate-700">Skills</label>
                            <input x-model="profile.skills" placeholder="PHP, Laravel, MySQL..." class="mt-1 w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="text-sm font-medium text-slate-700">Languages</label>
                            <input x-model="profile.languages" class="mt-1 w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-semibold text-slate-900">Experience</h2>
                        <button @click="addExperience" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">+ Add</button>
                    </div>
                    <template x-for="(exp, i) in profile.experience" :key="i">
                        <div class="mb-3 rounded-lg border border-slate-200 p-4">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <input x-model="exp.position" placeholder="Position" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <input x-model="exp.company" placeholder="Company" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <input x-model="exp.start_date" placeholder="Start date" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <input x-model="exp.end_date" placeholder="End date (or Present)" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div class="mt-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium text-slate-500">Description</span>
                                    <button @click="enhance('experience', () => exp.description, v => exp.description = v)" :disabled="isEnhancing('experience', exp.description)" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50">
                                        <span x-show="!isEnhancing('experience', exp.description)">&#10024; Enhance</span>
                                        <span x-show="isEnhancing('experience', exp.description)">Enhancing&hellip;</span>
                                    </button>
                                </div>
                                <textarea x-model="exp.description" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                            </div>
                            <button @click="removeExperience(i)" class="mt-2 text-xs font-medium text-red-500 hover:text-red-700">Remove</button>
                        </div>
                    </template>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-semibold text-slate-900">Projects</h2>
                        <button @click="addProject" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">+ Add</button>
                    </div>
                    <template x-if="profile.projects.length === 0">
                        <p class="text-sm text-slate-400">No projects listed &mdash; add one, or skip straight to matching jobs.</p>
                    </template>
                    <template x-for="(proj, i) in profile.projects" :key="i">
                        <div class="mb-3 rounded-lg border border-slate-200 p-4">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <input x-model="proj.title" placeholder="Project name" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <input x-model="proj.technologies" placeholder="Technologies" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div class="mt-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium text-slate-500">Description</span>
                                    <button @click="enhance('project', () => proj.description, v => proj.description = v)" :disabled="isEnhancing('project', proj.description)" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50">
                                        <span x-show="!isEnhancing('project', proj.description)">&#10024; Enhance</span>
                                        <span x-show="isEnhancing('project', proj.description)">Enhancing&hellip;</span>
                                    </button>
                                </div>
                                <textarea x-model="proj.description" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                            </div>
                            <button @click="removeProject(i)" class="mt-2 text-xs font-medium text-red-500 hover:text-red-700">Remove</button>
                        </div>
                    </template>
                </div>

                <div class="flex items-center justify-between">
                    <button @click="reset" class="text-sm font-medium text-slate-500 hover:text-slate-700">&larr; Start over</button>
                    <button @click="findMatches" :disabled="loading" class="rounded-lg bg-indigo-600 px-5 py-2.5 font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                        <span x-show="!loading">Find matching jobs &rarr;</span>
                        <span x-show="loading">Searching&hellip;</span>
                    </button>
                </div>
            </div>
        </template>
    </div>

    {{-- STEP 3: matches --}}
    <div x-show="step === 3" x-cloak>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-slate-900">Your matches</h1>
            <span x-show="jobsMeta" class="text-sm text-slate-500">
                Average compatibility: <span class="font-semibold text-slate-700" x-text="jobsMeta ? jobsMeta.average_score + '%' : ''"></span>
            </span>
        </div>

        <template x-if="jobs.length === 0">
            <p class="text-sm text-slate-400">No jobs came back for this search.</p>
        </template>

        <div class="space-y-4">
            <template x-for="job in jobs" :key="job.job_id">
                <div class="flex items-start justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5">
                    <div class="min-w-0">
                        <h3 class="font-semibold text-slate-900" x-text="job.job_title"></h3>
                        <p class="text-sm text-slate-500" x-text="[job.employer_name, job.job_location].filter(Boolean).join(' · ')"></p>
                        <ul class="mt-2 list-inside list-disc space-y-0.5 text-sm text-slate-600">
                            <template x-for="reason in job.match_reasons" :key="reason">
                                <li x-text="reason"></li>
                            </template>
                        </ul>
                        <a :href="job.job_apply_link" target="_blank" rel="noopener" class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800">Apply &rarr;</a>
                    </div>
                    <span class="shrink-0 rounded-full px-3 py-1 text-sm font-semibold ring-1 ring-inset" :class="scoreColor(job.compatibility)" x-text="job.compatibility + '%'"></span>
                </div>
            </template>
        </div>

        <button @click="reset" class="mt-8 text-sm font-medium text-slate-500 hover:text-slate-700">&larr; Start over</button>
    </div>
</div>
@endsection

import './bootstrap';
import Alpine from 'alpinejs';

window.axios.defaults.withCredentials = true;

function emptyExperience() {
    return { position: '', company: '', start_date: '', end_date: '', description: '' };
}

function emptyProject() {
    return { title: '', description: '', technologies: '', duration: '' };
}

Alpine.data('wizard', () => ({
    step: 1,
    loading: false,
    error: null,
    notice: null,
    profile: null,
    jobs: [],
    jobsMeta: null,
    enhancing: {},

    async uploadSample() {
        this.loading = true;
        this.error = null;
        try {
            const { data } = await window.axios.post('/wizard/upload-cv', { sample: true });
            this.applyProfile(data);
        } catch (e) {
            this.error = e.response?.data?.message || 'Something went wrong loading the sample CV.';
        }
        this.loading = false;
    },

    async uploadFile(event) {
        const file = event.target.files[0];
        if (!file) return;

        this.loading = true;
        this.error = null;
        const form = new FormData();
        form.append('cv', file);

        try {
            const { data } = await window.axios.post('/wizard/upload-cv', form);
            if (data.success) {
                this.applyProfile(data);
            } else {
                this.error = data.message;
            }
        } catch (e) {
            this.error = e.response?.data?.message || 'Could not read that PDF. Try another file.';
        }
        this.loading = false;
        event.target.value = '';
    },

    applyProfile(data) {
        const profile = data.data;
        profile.experience = profile.experience?.length ? profile.experience : [emptyExperience()];
        profile.projects = profile.projects || [];
        this.profile = profile;
        this.notice = profile._demo_limited
            ? data.message
            : (profile._sample ? 'This is a sample CV — edit it however you like.' : null);
        this.step = 2;
    },

    addExperience() {
        this.profile.experience.push(emptyExperience());
    },

    removeExperience(index) {
        this.profile.experience.splice(index, 1);
    },

    addProject() {
        this.profile.projects.push(emptyProject());
    },

    removeProject(index) {
        this.profile.projects.splice(index, 1);
    },

    async enhance(section, getText, setText) {
        const key = section + ':' + getText();
        this.enhancing[key] = true;
        try {
            const { data } = await window.axios.post('/wizard/enhance', {
                text: getText(),
                section,
            });
            if (data.success) {
                setText(data.data);
            } else {
                this.error = data.message;
            }
        } catch (e) {
            this.error = e.response?.data?.message || 'Enhance failed — try again in a bit.';
        }
        this.enhancing[key] = false;
    },

    isEnhancing(section, text) {
        return !!this.enhancing[section + ':' + text];
    },

    async findMatches() {
        this.loading = true;
        this.error = null;
        try {
            const { data } = await window.axios.post('/wizard/jobs', {
                skills: this.profile.skills,
                summary: this.profile.summary,
                position: this.profile.position,
                experience: this.profile.experience,
                projects: this.profile.projects,
            });
            this.jobs = data.jobs || [];
            this.jobsMeta = data.meta || null;
            this.notice = data.meta?.demo_limited
                ? "Today's demo AI budget is used up — showing sample matches instead."
                : (data.meta?.sample ? 'Showing sample job matches (no live job search configured for this demo).' : null);
            this.step = 3;
        } catch (e) {
            this.error = e.response?.data?.message || 'Something went wrong finding matches.';
        }
        this.loading = false;
    },

    reset() {
        this.step = 1;
        this.profile = null;
        this.jobs = [];
        this.jobsMeta = null;
        this.error = null;
        this.notice = null;
    },

    scoreColor(score) {
        if (score >= 75) return 'bg-emerald-100 text-emerald-800 ring-emerald-600/20';
        if (score >= 45) return 'bg-amber-100 text-amber-800 ring-amber-600/20';
        return 'bg-slate-100 text-slate-600 ring-slate-500/20';
    },
}));

window.Alpine = Alpine;
Alpine.start();

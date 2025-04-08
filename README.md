# Find Job with AI

This project is a Laravel-based web application that leverages AI to assist users in finding jobs and analyzing their CVs. It integrates with external APIs and AI models to provide job recommendations, CV analysis, and text enhancement.

## Features

- **Job Search**: Search for jobs using the JSearch API with filters like position, country, and date posted.
- **CV Analysis**: Upload a CV in PDF format to extract structured information such as skills, experience, and education.
- **Job Compatibility Analysis**: Match candidate profiles with job descriptions and calculate compatibility scores using AI.
- **Text Enhancement**: Improve CV sections (e.g., experience, projects, education) with AI-powered rewriting.
- **Test Mode**: Simulate API responses for testing purposes.

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/your-username/find-job-with-ai.git
   cd find-job-with-ai
   ```

2. Install dependencies:
   ```bash
   composer install
   npm install
   ```

3. Copy the `.env.example` file to `.env` and configure your environment variables:
   ```bash
   cp .env.example .env
   ```

4. Generate the application key:
   ```bash
   php artisan key:generate
   ```

5. Set up your database and run migrations:
   ```bash
   php artisan migrate
   ```

6. Configure the following API keys in your `.env` file:
   - `RAPIDAPI_KEY`: Your API key for the JSearch API.
   - `GEMINI_AI_API_KEY`: Your API key for the Gemini AI service.

7. Start the development server:
   ```bash
   php artisan serve
   ```

8. Compile frontend assets:
   ```bash
   npm run dev
   ```

## API Endpoints

### Job Search

- **Endpoint**: `/api/jobs/search`
- **Method**: `GET`
- **Parameters**:
  - `position` (optional): Job position to search for.
  - `country` (optional): Country code (e.g., `us`, `jo`).
  - `page` (optional): Page number.
  - `num_pages` (optional): Number of pages to fetch.
  - `date_posted` (optional): Filter by date posted (`all`, `last_7_days`, etc.).

### CV Analysis

- **Endpoint**: `/api/cv/analyze`
- **Method**: `POST`
- **Parameters**:
  - `cv` (required): PDF file of the CV.

### Text Enhancement

- **Endpoint**: `/api/cv/enhance`
- **Method**: `POST`
- **Parameters**:
  - `text` (required): Text to enhance.
  - `section` (optional): Section type (`experience`, `project`, `education`, `summary`).

## Technologies Used

- **Backend**: Laravel
- **Frontend**: Blade templates, Vite
- **AI Integration**: Gemini AI
- **APIs**: JSearch API
- **PDF Parsing**: Smalot PDF Parser

## Testing

Run the test suite using PHPUnit:
```bash
php artisan test
```

## Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or bugfix.
3. Commit your changes and push to your fork.
4. Submit a pull request.

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Contact

For questions or support, please contact the project maintainer at [mehmetkado9@gmail.com].
# ML_model
Multi-Tenant SAAS ML Model

## Status summary
The repository includes a local React/Laravel workspace flow for account access, organizations, projects, dataset uploads, and ML job submission.

### Implemented
- Phase 1: auth and organization membership flow
- Phase 2: plan, feature, and quota foundation
- Phase 3: dataset upload and lightweight analysis
- Phase 4: private dataset transfer, model training, and artifact persistence
- User registration and login via Laravel Sanctum tokens
- Authenticated user profile endpoint and logout flow
- Organization creation with automatic owner membership
- Organization listing filtered to authenticated memberships
- Default platform roles seeded for `super_admin`, `organization_owner`, `organization_admin`, and `organization_member`
- Feature registry and plan setup for SaaS entitlements
- Subscription activation for an organization
- Usage tracking and quota enforcement for plan-based features
- Dataset upload and metadata analysis for CSV/XLSX sources
- Project creation and ML service job submission flow
- Classification and regression training with real holdout metrics
- Private preprocessing/model pipelines saved as Joblib artifacts
- React workspace UI connected to the Laravel API

### Key API endpoints
- `POST /api/register`
- `POST /api/login`
- `GET /api/user`
- `POST /api/logout`
- `GET /api/organizations`
- `POST /api/organizations`
- `GET /api/plans`
- `GET /api/features`
- `GET /api/organizations/{organization}/entitlements`
- `POST /api/organizations/{organization}/subscription`
- `POST /api/organizations/{organization}/usage`
- `GET|POST /api/projects`
- `POST /api/projects/{project}/train`
- `GET /api/projects/{project}/jobs`
- `GET|POST /api/datasets`

### Local development commands
Run each service in a separate PowerShell terminal. The API uses port `8000`, the ML service uses `8001`, and Vite uses `5173`.

#### Laravel API
```powershell
cd C:\wamp64\www\ML_model\apps\api
if (!(Test-Path .env)) { Copy-Item .env.example .env }
if (!(Test-Path database\database.sqlite)) { New-Item -ItemType File database\database.sqlite }
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Set `ML_SERVICE_URL`, `ML_SERVICE_TOKEN`, and `ML_TRAINING_TIMEOUT_SECONDS` in `apps\api\.env`. Set the same `ML_SERVICE_TOKEN` in `services\ml\.env`; keep this credential server-side and never put it in the frontend.

#### React frontend
```powershell
cd C:\wamp64\www\ML_model\apps\web
npm install
npm run dev
```

The frontend reads the API URL from `VITE_API_URL`. Copy `.env.example` to `.env.local` only if you need to override the default `http://127.0.0.1:8000/api`.

#### Python ML service
```powershell
cd C:\wamp64\www\ML_model\services\ml
python -m venv .venv
.\.venv\Scripts\Activate.ps1
if (!(Test-Path .env)) { Copy-Item .env.example .env }
pip install -r requirements.txt
uvicorn app.main:app --reload --host 127.0.0.1 --port 8001
```

The API transfers uploaded files directly from Laravel's private storage to the token-protected ML service; files are not made public. Training supports Linear Regression, Decision Tree, and Random Forest for regression, plus Logistic Regression, Decision Tree, and Random Forest for classification. Pass the estimator in `params.estimator` (`linear_regression`, `logistic_regression`, `decision_tree`, or `random_forest`); it defaults to Random Forest for the existing UI.

Open the Vite URL printed in the frontend terminal, usually `http://localhost:5173`. Create an account, create an organization and project, upload a CSV/XLSX/XLS dataset, and submit a training request. Completed job records contain actual holdout metrics and a model artifact path relative to the private ML artifacts directory. The frontend results display is a separate follow-up phase.

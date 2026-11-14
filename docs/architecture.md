# ModelForge architecture

## System overview

ModelForge follows a layered SaaS architecture:

- Frontend: React SPA for organizations, datasets, projects, jobs, and reports
- API: Laravel monolith with REST endpoints and service-oriented business logic
- ML service: FastAPI worker that receives training jobs and returns metrics/artifacts
- Data layer: PostgreSQL for tenant-owned records, object storage for uploaded files, Redis for queues

## Multi-tenant model

Every tenant-owned resource uses an organization_id and is accessed through scoped queries.

### Core entities

- organizations
- organization_memberships
- projects
- datasets
- model_runs
- models
- predictions
- jobs
- subscriptions
- package_limits

## Authorization model

Roles:

- super_admin
- organization_owner
- organization_admin
- organization_member

Permissions are enforced by policy classes and middleware, never by frontend filtering.

## ML workflow

1. Upload dataset
2. Validate schema and column types
3. Preprocess values and feature engineering
4. Start training job
5. Evaluate model using validation metrics
6. Persist artifact and metadata
7. Generate predictions and reports

## Security

- Email verification for registration
- SANCTUM token auth for SPA/API clients
- Tenant-scoped policies and middleware
- Audit logging for admin events
- File validation and private storage by default
- Optional PostgreSQL Row-Level Security for defense-in-depth

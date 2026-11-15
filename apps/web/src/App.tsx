import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react'

const API_URL = import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api'
const inputClass =
  'mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-cyan-400'
const buttonClass =
  'rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-50'

const estimators = {
  regression: [
    { value: 'linear_regression', label: 'Linear Regression' },
    { value: 'decision_tree', label: 'Decision Tree' },
    { value: 'random_forest', label: 'Random Forest' },
  ],
  classification: [
    { value: 'logistic_regression', label: 'Logistic Regression' },
    { value: 'decision_tree', label: 'Decision Tree' },
    { value: 'random_forest', label: 'Random Forest' },
  ],
}

const estimatorLabels = Object.fromEntries(
  [...estimators.regression, ...estimators.classification].map(({ value, label }) => [value, label]),
)
const metricLabels: Record<string, string> = {
  accuracy: 'Accuracy',
  precision_weighted: 'Weighted precision',
  recall_weighted: 'Weighted recall',
  f1_weighted: 'Weighted F1',
  r2: 'R²',
  mae: 'Mean absolute error',
  rmse: 'Root mean squared error',
}

type User = {
  id: number
  name: string
  email: string
  email_verified_at?: string | null
  can_create_organization?: boolean
}
type Organization = {
  id: string
  name: string
  slug: string
  role: string
  status: string
  registration_policy: string
  logo_url: string | null
  description: string | null
  industry: string | null
  contact_email: string | null
  phone: string | null
  address: string | null
  timezone: string
  created_at: string
}
type WorkspacePage = 'dashboard' | 'predictions' | 'company' | 'users' | 'permissions' | 'settings'
type CompanyMember = {
  id: string
  user_id: number
  name: string
  email: string
  role: string
  status: string
  joined_at: string | null
}
type OrganizationInvitation = {
  id: string
  organization_id?: string
  organization_name?: string
  email: string
  role: string
  status: string
  expires_at: string
}
type CompanyPermissions = {
  current_role: string
  current_permissions: string[]
  roles: Array<{ role: string; permissions: string[] }>
}
const workspacePages: Array<{ id: WorkspacePage; label: string }> = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'predictions', label: 'Predictions' },
  { id: 'company', label: 'Company Profile' },
  { id: 'users', label: 'Users' },
  { id: 'permissions', label: 'Roles & Permissions' },
  { id: 'settings', label: 'Company Settings' },
]
type Project = {
  id: string
  organization_id: string
  name: string
  description: string | null
  dataset_count: number
}
type Dataset = {
  id: string
  organization_id: string
  project_id: string | null
  name: string
  status: string
  metadata: { row_count?: number; column_count?: number; columns?: string[] } | null
}
type TrainingResult = {
  status?: string
  model_id?: string
  artifact_path?: string
  estimator?: string
  problem_type?: 'classification' | 'regression'
  metrics?: Record<string, number>
  message?: string
  source?: string
}
type MlJob = {
  id: string
  status: string
  job_type: string
  model_name: string
  target_column: string | null
  result: TrainingResult | null
  created_at: string
}
type PredictionFeature = { name: string; type: 'number' | 'category' }
type PredictionModel = {
  id: string
  name: string
  algorithm: string
  problem_type: 'classification' | 'regression'
  target_column: string
  metrics: Record<string, number>
  trained_at: string | null
  features?: PredictionFeature[]
  supports_probabilities?: boolean
}
type PredictionProbability = { class: string | number | boolean | null; probability: number }
type PredictionRow = {
  row: number
  prediction: string | number | boolean | null
  probabilities: PredictionProbability[] | null
}
type PredictionRun = {
  id: string
  name: string
  model_id: string
  model_name: string | null
  input_filename: string
  row_count: number | null
  status: string
  error_message: string | null
  created_at: string
  completed_at: string | null
}
type PredictionRunDetails = {
  prediction_run: PredictionRun
  results: {
    predictions: PredictionRow[]
    metadata: {
      algorithm: string
      problem_type: 'classification' | 'regression'
      target_column: string
      feature_schema: PredictionFeature[]
      supports_probabilities: boolean
    }
  } | null
}
type ApiError = { message?: string; errors?: Record<string, string[]> }

function parseCsvPreview(text: string, maxRows = 7): string[][] {
  const rows: string[][] = []
  let row: string[] = []
  let field = ''
  let quoted = false
  const source = text.replace(/^\uFEFF/, '')

  for (let index = 0; index < source.length && rows.length < maxRows; index += 1) {
    const character = source[index]
    if (quoted) {
      if (character === '"' && source[index + 1] === '"') {
        field += '"'
        index += 1
      } else if (character === '"') {
        quoted = false
      } else {
        field += character
      }
    } else if (character === '"' && field.length === 0) {
      quoted = true
    } else if (character === ',') {
      row.push(field)
      field = ''
    } else if (character === '\n' || character === '\r') {
      if (character === '\r' && source[index + 1] === '\n') index += 1
      row.push(field)
      if (row.some((cell) => cell.length > 0)) rows.push(row)
      row = []
      field = ''
    } else {
      field += character
    }
  }

  if (rows.length < maxRows && (field.length > 0 || row.length > 0)) {
    row.push(field)
    if (row.some((cell) => cell.length > 0)) rows.push(row)
  }
  return rows
}

function displayJobStatus(status: string): string {
  const labels: Record<string, string> = {
    queued: 'Pending',
    pending: 'Pending',
    running: 'Processing',
    processing: 'Processing',
    completed: 'Completed',
    failed: 'Failed',
  }
  return labels[status] ?? status
}

function isWorkspacePage(page: string | null): page is WorkspacePage {
  return workspacePages.some((item) => item.id === page)
}

function jobStatusClasses(status: string): string {
  if (status === 'completed') return 'bg-emerald-500/10 text-emerald-300'
  if (status === 'failed') return 'bg-rose-500/10 text-rose-300'
  if (status === 'running' || status === 'processing') return 'bg-amber-500/10 text-amber-300'
  return 'bg-slate-700 text-slate-300'
}

function isCompletedWithResults(job: MlJob): boolean {
  const result = job.result
  const metrics = result?.metrics
  return Boolean(
    job.status === 'completed' &&
      result?.status === 'completed' &&
      result.model_id &&
      result.estimator &&
      result.problem_type &&
      metrics &&
      Object.keys(metrics).length > 0 &&
      Object.values(metrics).every(Number.isFinite),
  )
}

async function request<T>(
  path: string,
  token: string | null,
  options: RequestInit = {},
): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)
  if (options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }

  const response = await fetch(`${API_URL}${path}`, { ...options, headers })
  const data = (await response.json().catch(() => ({}))) as T & ApiError

  if (!response.ok) {
    const validationMessage = data.errors ? Object.values(data.errors).flat()[0] : undefined
    throw new Error(validationMessage ?? data.message ?? `Request failed (${response.status})`)
  }

  return data
}

function App() {
  const [token, setToken] = useState(() => localStorage.getItem('modelforge_token'))
  const [user, setUser] = useState<User | null>(null)
  const [organizations, setOrganizations] = useState<Organization[]>([])
  const [projects, setProjects] = useState<Project[]>([])
  const [datasets, setDatasets] = useState<Dataset[]>([])
  const [jobs, setJobs] = useState<MlJob[]>([])
  const [predictionModels, setPredictionModels] = useState<PredictionModel[]>([])
  const [selectedModelId, setSelectedModelId] = useState('')
  const [selectedModel, setSelectedModel] = useState<PredictionModel | null>(null)
  const [predictionRuns, setPredictionRuns] = useState<PredictionRun[]>([])
  const [selectedPrediction, setSelectedPrediction] = useState<PredictionRunDetails | null>(null)
  const [predictionFile, setPredictionFile] = useState<File | null>(null)
  const [predictionPreview, setPredictionPreview] = useState<string[][]>([])
  const [predictionFileError, setPredictionFileError] = useState('')
  const [predictionName, setPredictionName] = useState('')
  const [predictionBusy, setPredictionBusy] = useState(false)
  const [predictionDetailBusy, setPredictionDetailBusy] = useState(false)
  const [modelsBusy, setModelsBusy] = useState(false)
  const [exportBusy, setExportBusy] = useState(false)
  const [organizationId, setOrganizationId] = useState('')
  const [workspaceLoaded, setWorkspaceLoaded] = useState(false)
  const [activePage, setActivePage] = useState<WorkspacePage>('dashboard')
  const [members, setMembers] = useState<CompanyMember[]>([])
  const [organizationInvitations, setOrganizationInvitations] = useState<OrganizationInvitation[]>([])
  const [userInvitations, setUserInvitations] = useState<OrganizationInvitation[]>([])
  const [companyPermissions, setCompanyPermissions] = useState<CompanyPermissions | null>(null)
  const [projectId, setProjectId] = useState('')
  const [datasetId, setDatasetId] = useState('')
  const [targetColumn, setTargetColumn] = useState('')
  const [problemType, setProblemType] = useState<'classification' | 'regression'>('classification')
  const [estimator, setEstimator] = useState('random_forest')
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login')
  const [busy, setBusy] = useState(false)
  const [trainingBusy, setTrainingBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const workspaceLoadRef = useRef<{ token: string; promise: Promise<void> } | null>(null)
  const predictionRequestRef = useRef(0)
  const predictionDetailRequestRef = useRef(0)
  const predictionBusyRef = useRef(false)
  const activeWorkspaceRef = useRef({ organizationId, token })
  activeWorkspaceRef.current = { organizationId, token }
  const invitationContextRef = useRef({
    id: new URLSearchParams(window.location.search).get('invitation') ?? '',
    token: new URLSearchParams(window.location.search).get('token') ?? '',
  })

  const selectedDataset = useMemo(
    () => datasets.find((dataset) => dataset.id === datasetId),
    [datasets, datasetId],
  )
  const availableEstimators = estimators[problemType]

  const loadWorkspace = useCallback((activeToken: string): Promise<void> => {
    const existingLoad = workspaceLoadRef.current
    if (existingLoad?.token === activeToken) return existingLoad.promise

    const loadPromise = (async () => {
      const [organizationData, projectData, datasetData, userData, invitationData] = await Promise.all([
        request<Organization[]>('/organizations', activeToken),
        request<Project[]>('/projects', activeToken),
        request<Dataset[]>('/datasets', activeToken),
        request<User>('/user', activeToken),
        request<OrganizationInvitation[]>('/invitations', activeToken),
      ])

      const organizationKey = `modelforge_organization_${userData.id}`
      const savedOrganizationId = localStorage.getItem(organizationKey)
      const activeOrganization = organizationData.length === 1
        ? organizationData[0]
        : organizationData.find((organization) => organization.id === savedOrganizationId)
      const activeOrganizationId = activeOrganization?.id ?? ''

      if (activeOrganizationId) localStorage.setItem(organizationKey, activeOrganizationId)
      else localStorage.removeItem(organizationKey)

      setOrganizations(organizationData)
      setProjects(projectData)
      setDatasets(datasetData)
      setUser(userData)
      setUserInvitations(invitationData)
      setOrganizationId(activeOrganizationId)
      const savedPage = localStorage.getItem(`modelforge_page_${userData.id}_${activeOrganizationId}`)
      setActivePage(invitationContextRef.current.id ? 'users' : isWorkspacePage(savedPage) ? savedPage : 'dashboard')
      setProjectId((current) =>
        projectData.some((project) => project.id === current && project.organization_id === activeOrganizationId)
          ? current
          : projectData.find((project) => project.organization_id === activeOrganizationId)?.id ?? '',
      )
      setWorkspaceLoaded(true)
    })()

    workspaceLoadRef.current = { token: activeToken, promise: loadPromise }
    void loadPromise.then(
      () => {
        if (workspaceLoadRef.current?.promise === loadPromise) workspaceLoadRef.current = null
      },
      () => {
        if (workspaceLoadRef.current?.promise === loadPromise) workspaceLoadRef.current = null
      },
    )
    return loadPromise
  }, [])

  useEffect(() => {
    if (!token) return

    loadWorkspace(token).catch((requestError: Error) => {
      setError(requestError.message)
      if (requestError.message.includes('Unauthenticated')) {
        localStorage.removeItem('modelforge_token')
        setToken(null)
      }
    })
  }, [loadWorkspace, token])

  useEffect(() => {
    if (!token || !projectId) {
      setJobs([])
      return
    }

    request<MlJob[]>(`/projects/${projectId}/jobs`, token)
      .then(setJobs)
      .catch((requestError: Error) => setError(requestError.message))
  }, [projectId, token])

  useEffect(() => {
    if (!token || !organizationId || activePage !== 'predictions') return

    let active = true
    setModelsBusy(true)
    Promise.all([
      request<{ models: PredictionModel[] }>(`/organizations/${organizationId}/models`, token),
      request<{ prediction_runs: PredictionRun[] }>(`/organizations/${organizationId}/predictions`, token),
    ])
      .then(([modelData, runData]) => {
        if (!active) return
        setPredictionModels(modelData.models)
        setPredictionRuns(runData.prediction_runs)
        setSelectedModelId((current) =>
          modelData.models.some((model) => model.id === current) ? current : modelData.models[0]?.id ?? '',
        )
      })
      .catch((requestError: Error) => {
        if (active) setError(requestError.message)
      })
      .finally(() => {
        if (active) setModelsBusy(false)
      })

    return () => {
      active = false
    }
  }, [activePage, organizationId, token])

  useEffect(() => {
    if (!token || !organizationId || !selectedModelId || activePage !== 'predictions') {
      setSelectedModel(null)
      return
    }

    let active = true
    request<{ model: PredictionModel }>(
      `/organizations/${organizationId}/models/${selectedModelId}`,
      token,
    )
      .then((response) => {
        if (active) setSelectedModel(response.model)
      })
      .catch((requestError: Error) => {
        if (active) {
          setSelectedModel(null)
          setError(requestError.message)
        }
      })

    return () => {
      active = false
    }
  }, [activePage, organizationId, selectedModelId, token])

  useEffect(() => {
    const columns = selectedDataset?.metadata?.columns ?? []
    setTargetColumn((current) => (columns.includes(current) ? current : columns[0] ?? ''))
  }, [selectedDataset])

  useEffect(() => {
    const activeOrganization = organizations.find((organization) => organization.id === organizationId)
    if (!token || !activeOrganization) {
      setMembers([])
      setOrganizationInvitations([])
      setCompanyPermissions(null)
      return
    }

    const invitationsRequest = ['organization_owner', 'organization_admin'].includes(activeOrganization.role)
      ? request<OrganizationInvitation[]>(`/organizations/${organizationId}/invitations`, token)
      : Promise.resolve([])

    Promise.all([
      request<CompanyMember[]>(`/organizations/${organizationId}/users`, token),
      request<CompanyPermissions>(`/organizations/${organizationId}/permissions`, token),
      invitationsRequest,
    ])
      .then(([memberData, permissionData, invitationData]) => {
        setMembers(memberData)
        setOrganizationInvitations(invitationData)
        setCompanyPermissions(permissionData)
      })
      .catch((requestError: Error) => setError(requestError.message))
  }, [organizationId, organizations, token])

  async function submitAuth(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setBusy(true)
    const formData = new FormData(event.currentTarget)
    const payload: Record<string, string> = {
      email: String(formData.get('email') ?? ''),
      password: String(formData.get('password') ?? ''),
    }
    if (authMode === 'register') {
      payload.name = String(formData.get('name') ?? '')
      payload.password_confirmation = String(formData.get('password_confirmation') ?? '')
    }

    try {
      const result = await request<{ token: string; user: User }>(
        `/${authMode === 'register' ? 'register' : 'login'}`,
        null,
        { method: 'POST', body: JSON.stringify(payload) },
      )
      localStorage.setItem('modelforge_token', result.token)
      setWorkspaceLoaded(false)
      setToken(result.token)
      setUser(result.user)
      setNotice(`Welcome${result.user.name ? `, ${result.user.name}` : ''}.`)
    } catch (requestError) {
      setError((requestError as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function submitOrganization(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token) return
    setError('')
    setBusy(true)
    const form = event.currentTarget
    const formData = new FormData(form)
    try {
      await request('/organizations', token, {
        method: 'POST',
        body: JSON.stringify({ name: formData.get('name') }),
      })
      form.reset()
      await loadWorkspace(token)
      setNotice('Organization created.')
    } catch (requestError) {
      setError((requestError as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function resendVerification() {
    if (!token) return
    try {
      const result = await request<{ message: string }>('/email/verification-notification', token, { method: 'POST' })
      setNotice(result.message)
      setError('')
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function submitCompanyProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !organizationId) return
    setError('')
    const formData = new FormData(event.currentTarget)
    const payload = Object.fromEntries(
      ['name', 'logo_url', 'description', 'industry', 'contact_email', 'phone', 'address', 'timezone']
        .map((field) => [field, String(formData.get(field) ?? '').trim()]),
    )

    try {
      const result = await request<{ organization: Organization }>(`/organizations/${organizationId}`, token, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      setOrganizations((current) => current.map((organization) =>
        organization.id === organizationId ? result.organization : organization,
      ))
      setNotice('Company profile updated.')
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function submitCompanySettings(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !organizationId) return
    const formData = new FormData(event.currentTarget)

    try {
      const result = await request<{ message: string; registration_policy: string }>(
        `/organizations/${organizationId}/settings`,
        token,
        { method: 'PATCH', body: JSON.stringify({ registration_policy: formData.get('registration_policy') }) },
      )
      setOrganizations((current) => current.map((organization) =>
        organization.id === organizationId
          ? { ...organization, registration_policy: result.registration_policy }
          : organization,
      ))
      setNotice(result.message)
      setError('')
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function submitInvitation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !organizationId) return
    setError('')
    const form = event.currentTarget
    const formData = new FormData(form)

    try {
      await request(`/organizations/${organizationId}/invitations`, token, {
        method: 'POST',
        body: JSON.stringify({
          email: formData.get('email'),
          role: formData.get('role'),
        }),
      })
      const updatedInvitations = await request<OrganizationInvitation[]>(
        `/organizations/${organizationId}/invitations`,
        token,
      )
      setOrganizationInvitations(updatedInvitations)
      form.reset()
      setNotice('Invitation sent.')
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function updateMemberRole(member: CompanyMember, role: string) {
    if (!token || !organizationId) return
    setError('')
    try {
      await request(`/organizations/${organizationId}/memberships/${member.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ role }),
      })
      if (role === 'organization_owner') {
        await loadWorkspace(token)
      } else {
        setMembers((current) => current.map((item) => item.id === member.id ? { ...item, role } : item))
      }
      setNotice(`Updated ${member.name}'s role.`)
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function revokeMember(member: CompanyMember) {
    if (!token || !organizationId) return
    setError('')
    try {
      await request(`/organizations/${organizationId}/memberships/${member.id}`, token, { method: 'DELETE' })
      setMembers((current) => current.map((item) => item.id === member.id ? { ...item, status: 'revoked' } : item))
      setNotice(`${member.name}'s access was revoked.`)
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function revokeInvitation(invitation: OrganizationInvitation) {
    if (!token || !organizationId) return
    setError('')
    try {
      await request(`/organizations/${organizationId}/invitations/${invitation.id}`, token, { method: 'DELETE' })
      setOrganizationInvitations((current) => current.filter((item) => item.id !== invitation.id))
      setNotice('Invitation revoked.')
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function respondToInvitation(invitation: OrganizationInvitation, action: 'accept' | 'reject') {
    const invitationToken = invitationContextRef.current.token
    if (!token || !invitationToken || invitationContextRef.current.id !== invitation.id) {
      setError('Open the secure invitation link from your email to respond.')
      return
    }

    setError('')
    try {
      const result = await request<{ message: string }>(`/invitations/${invitation.id}/${action}`, token, {
        method: 'POST',
        body: JSON.stringify({ token: invitationToken }),
      })
      invitationContextRef.current = { id: '', token: '' }
      const currentUrl = new URL(window.location.href)
      currentUrl.searchParams.delete('invitation')
      currentUrl.searchParams.delete('token')
      window.history.replaceState({}, '', `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`)
      setNotice(result.message)
      await loadWorkspace(token)
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  function navigateWorkspace(page: WorkspacePage) {
    setActivePage(page)
    if (user && organizationId) {
      localStorage.setItem(`modelforge_page_${user.id}_${organizationId}`, page)
    }
  }

  async function submitProject(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !organizationId) return
    setError('')
    const form = event.currentTarget
    const formData = new FormData(form)
    try {
      await request('/projects', token, {
        method: 'POST',
        body: JSON.stringify({
          organization_id: organizationId,
          name: formData.get('name'),
          description: formData.get('description'),
        }),
      })
      form.reset()
      await loadWorkspace(token)
      setNotice('Project created.')
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function submitDataset(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !organizationId) return
    setError('')
    const form = event.currentTarget
    const formData = new FormData(form)
    formData.set('organization_id', organizationId)
    if (projectId) formData.set('project_id', projectId)

    try {
      await request('/datasets', token, { method: 'POST', body: formData })
      form.reset()
      await loadWorkspace(token)
      setNotice('Dataset uploaded and analyzed.')
    } catch (requestError) {
      setError((requestError as Error).message)
    }
  }

  async function submitTraining(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !projectId || !datasetId) return
    setError('')
    setNotice('')
    setTrainingBusy(true)
    const formData = new FormData(event.currentTarget)

    try {
      const submission = await request<{ job: MlJob }>(`/projects/${projectId}/train`, token, {
        method: 'POST',
        body: JSON.stringify({
          dataset_id: datasetId,
          model_name: formData.get('model_name'),
          target_column: targetColumn,
          algorithm: problemType,
          params: { estimator },
        }),
      })
      const refreshedJobs = await request<MlJob[]>(`/projects/${projectId}/jobs`, token)
      setJobs(refreshedJobs)
      const job = refreshedJobs.find((item) => item.id === submission.job.id) ?? submission.job

      if (job.status === 'failed') {
        setError(job.result?.message ?? 'Training failed. Check the dataset and target column, then try again.')
      } else if (isCompletedWithResults(job)) {
        setNotice('Training completed. Actual evaluation results are shown below.')
      } else if (job.status === 'completed') {
        setError('The backend marked this job completed without returning valid model metrics.')
      } else {
        setNotice(`Training job status: ${displayJobStatus(job.status)}.`)
      }
    } catch (requestError) {
      setError((requestError as Error).message)
    } finally {
      setTrainingBusy(false)
    }
  }

  async function selectPredictionFile(file: File | null) {
    setPredictionFile(file)
    setPredictionPreview([])
    setPredictionFileError('')
    if (!file) return
    if (!file.name.toLowerCase().endsWith('.csv')) {
      setPredictionFile(null)
      setPredictionFileError('Choose a CSV file.')
      return
    }
    if (file.size > 20 * 1024 * 1024) {
      setPredictionFile(null)
      setPredictionFileError('The CSV must be no larger than 20 MB.')
      return
    }

    try {
      const preview = parseCsvPreview(await file.text())
      if (preview.length < 2) {
        setPredictionFileError('The CSV must include a header and at least one data row.')
      }
      setPredictionPreview(preview)
      setPredictionName(file.name.replace(/\.csv$/i, ''))
    } catch {
      setPredictionFileError('The selected CSV could not be read.')
    }
  }

  async function refreshPredictionHistory() {
    if (!token || !organizationId) return
    const requestOrganizationId = organizationId
    const requestToken = token
    const response = await request<{ prediction_runs: PredictionRun[] }>(
      `/organizations/${requestOrganizationId}/predictions`,
      requestToken,
    )
    if (
      activeWorkspaceRef.current.organizationId === requestOrganizationId &&
      activeWorkspaceRef.current.token === requestToken
    ) {
      setPredictionRuns(response.prediction_runs)
    }
  }

  async function submitPrediction(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !organizationId || !selectedModel || !predictionFile || predictionBusyRef.current) return

    const headers = predictionPreview[0]?.map((header) => header.trim()) ?? []
    const missingFeatures = selectedModel.features?.filter((feature) => !headers.includes(feature.name)) ?? []
    if (missingFeatures.length > 0) {
      setPredictionFileError(`Missing required columns: ${missingFeatures.map((feature) => feature.name).join(', ')}`)
      return
    }
    if (predictionFileError || predictionPreview.length < 2) return

    setError('')
    setNotice('')
    predictionBusyRef.current = true
    setPredictionBusy(true)
    const requestId = ++predictionRequestRef.current
    const requestOrganizationId = organizationId
    const formData = new FormData()
    formData.set('model_id', selectedModel.id)
    formData.set('name', predictionName.trim() || predictionFile.name.replace(/\.csv$/i, ''))
    formData.set('file', predictionFile, predictionFile.name)

    try {
      const response = await request<PredictionRunDetails>(
        `/organizations/${requestOrganizationId}/predictions`,
        token,
        { method: 'POST', body: formData },
      )
      if (predictionRequestRef.current !== requestId) return
      setSelectedPrediction(response)
      setNotice('Prediction completed using the saved model.')
      try {
        await refreshPredictionHistory()
      } catch (historyError) {
        if (predictionRequestRef.current === requestId) {
          setNotice(`Prediction completed; history refresh failed: ${(historyError as Error).message}`)
        }
      }
    } catch (requestError) {
      if (predictionRequestRef.current !== requestId) return
      setError((requestError as Error).message)
      try {
        await refreshPredictionHistory()
      } catch (historyError) {
        if (predictionRequestRef.current === requestId) {
          setNotice(`Prediction history could not be refreshed: ${(historyError as Error).message}`)
        }
      }
    } finally {
      if (predictionRequestRef.current === requestId) {
        predictionBusyRef.current = false
        setPredictionBusy(false)
      }
    }
  }

  async function openPredictionRun(run: PredictionRun) {
    if (!token || !organizationId) return
    const requestId = ++predictionDetailRequestRef.current
    setPredictionDetailBusy(true)
    try {
      const response = await request<PredictionRunDetails>(
        `/organizations/${organizationId}/predictions/${run.id}`,
        token,
      )
      if (predictionDetailRequestRef.current === requestId) setSelectedPrediction(response)
    } catch (requestError) {
      if (predictionDetailRequestRef.current === requestId) setError((requestError as Error).message)
    } finally {
      if (predictionDetailRequestRef.current === requestId) setPredictionDetailBusy(false)
    }
  }

  async function exportPrediction(run: PredictionRun) {
    if (!token || !organizationId || exportBusy) return
    setExportBusy(true)
    try {
      const response = await fetch(
        `${API_URL}/organizations/${organizationId}/predictions/${run.id}/export`,
        { headers: { Accept: 'text/csv', Authorization: `Bearer ${token}` } },
      )
      if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as ApiError
        throw new Error(data.message ?? `Export failed (${response.status})`)
      }
      const url = URL.createObjectURL(await response.blob())
      const link = document.createElement('a')
      link.href = url
      link.download = `${run.id}-predictions.csv`
      link.click()
      URL.revokeObjectURL(url)
    } catch (requestError) {
      setError((requestError as Error).message)
    } finally {
      setExportBusy(false)
    }
  }

  async function logout() {
    predictionRequestRef.current += 1
    predictionDetailRequestRef.current += 1
    predictionBusyRef.current = false
    if (token) {
      try {
        await request('/logout', token, { method: 'POST' })
      } catch (logoutError) {
        setNotice(`Signed out locally; server logout failed: ${(logoutError as Error).message}`)
      }
    }
    localStorage.removeItem('modelforge_token')
    setToken(null)
    setUser(null)
    setOrganizations([])
    setProjects([])
    setDatasets([])
    setJobs([])
    setPredictionModels([])
    setSelectedModelId('')
    setSelectedModel(null)
    setPredictionRuns([])
    setSelectedPrediction(null)
    setPredictionFile(null)
    setPredictionPreview([])
    setPredictionFileError('')
    setPredictionBusy(false)
    setPredictionDetailBusy(false)
    setMembers([])
    setOrganizationInvitations([])
    setUserInvitations([])
    setCompanyPermissions(null)
    setOrganizationId('')
    setWorkspaceLoaded(false)
  }

  if (!token) {
    return (
      <main className="mx-auto flex min-h-screen max-w-lg items-center px-5 py-12">
        <section className="w-full rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-xl">
          <p className="text-sm uppercase tracking-[0.25em] text-cyan-400">ModelForge</p>
          <h1 className="mt-3 text-2xl font-semibold text-white">
            {authMode === 'login' ? 'Sign in to your workspace' : 'Create your account'}
          </h1>
          <p className="mt-2 text-sm text-slate-400">Connect to the local Laravel API at {API_URL}.</p>
          <form className="mt-6 space-y-4" onSubmit={submitAuth}>
            {authMode === 'register' && (
              <label className="block text-sm text-slate-300">
                Name
                <input className={inputClass} name="name" required autoComplete="name" />
              </label>
            )}
            <label className="block text-sm text-slate-300">
              Email
              <input className={inputClass} name="email" type="email" required autoComplete="email" />
            </label>
            <label className="block text-sm text-slate-300">
              Password
              <input
                className={inputClass}
                name="password"
                type="password"
                minLength={8}
                required
                autoComplete={authMode === 'login' ? 'current-password' : 'new-password'}
              />
            </label>
            {authMode === 'register' && (
              <label className="block text-sm text-slate-300">
                Confirm password
                <input
                  className={inputClass}
                  name="password_confirmation"
                  type="password"
                  minLength={8}
                  required
                  autoComplete="new-password"
                />
              </label>
            )}
            {error && <p className="text-sm text-rose-300">{error}</p>}
            <button className={`${buttonClass} w-full`} disabled={busy}>
              {busy ? 'Please wait…' : authMode === 'login' ? 'Sign in' : 'Create account'}
            </button>
          </form>
          <button
            className="mt-5 text-sm text-cyan-300 hover:text-cyan-200"
            onClick={() => {
              setAuthMode(authMode === 'login' ? 'register' : 'login')
              setError('')
            }}
            type="button"
          >
            {authMode === 'login' ? 'New here? Create an account' : 'Already registered? Sign in'}
          </button>
        </section>
      </main>
    )
  }

  if (!workspaceLoaded) {
    return (
      <main className="mx-auto flex min-h-screen max-w-xl items-center px-5 py-12">
        <section className="w-full rounded-2xl border border-slate-800 bg-slate-900 p-6">
          <h1 className="text-xl font-semibold text-white">Loading your workspace</h1>
          {error ? (
            <>
              <p className="mt-3 text-sm text-rose-300">{error}</p>
              <button
                className={`${buttonClass} mt-4`}
                onClick={() => {
                  setError('')
                  loadWorkspace(token).catch((requestError: Error) => setError(requestError.message))
                }}
              >
                Retry
              </button>
            </>
          ) : (
            <p className="mt-3 text-sm text-slate-400">Checking your organization memberships…</p>
          )}
        </section>
      </main>
    )
  }

  const orgProjects = projects.filter((project) => project.organization_id === organizationId)
  const orgDatasets = datasets.filter((dataset) => dataset.organization_id === organizationId)
  const activeOrganization = organizations.find((organization) => organization.id === organizationId)
  const isOrganizationOwner = activeOrganization?.role === 'organization_owner'
  const canManageCompany = ['organization_owner', 'organization_admin'].includes(activeOrganization?.role ?? '')
  const predictionHeaders = predictionPreview[0]?.map((heading) => heading.trim()) ?? []
  const missingPredictionFeatures = (selectedModel?.features ?? []).filter(
    (feature) => !predictionHeaders.includes(feature.name),
  )

  function selectOrganization(nextOrganizationId: string) {
    const selectedOrganization = organizations.find((organization) => organization.id === nextOrganizationId)
    if (!selectedOrganization) return

    setMembers([])
    setOrganizationInvitations([])
    setCompanyPermissions(null)
    setJobs([])
    setPredictionModels([])
    setSelectedModelId('')
    setSelectedModel(null)
    setPredictionRuns([])
    setSelectedPrediction(null)
    setPredictionFile(null)
    setPredictionPreview([])
    setPredictionFileError('')
    predictionRequestRef.current += 1
    predictionDetailRequestRef.current += 1
    predictionBusyRef.current = false
    setPredictionBusy(false)
    setPredictionDetailBusy(false)
    setOrganizationId(selectedOrganization.id)
    if (user) localStorage.setItem(`modelforge_organization_${user.id}`, selectedOrganization.id)
    const savedPage = localStorage.getItem(`modelforge_page_${user?.id}_${selectedOrganization.id}`)
    setActivePage(isWorkspacePage(savedPage) ? savedPage : 'dashboard')
    setProjectId(projects.find((project) => project.organization_id === selectedOrganization.id)?.id ?? '')
    setDatasetId('')
  }

  return (
    <main className="mx-auto max-w-7xl px-5 py-8">
      <header className="mb-8 flex flex-wrap items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
          <p className="text-sm uppercase tracking-[0.25em] text-cyan-400">ModelForge</p>
          <h1 className="mt-2 text-3xl font-semibold text-white">ML workspace</h1>
          <p className="mt-1 text-sm text-slate-400">Upload data, create projects, and submit training jobs.</p>
        </div>
        <div className="flex items-center gap-4">
          <span className="text-sm text-slate-300">{user?.name ?? user?.email}</span>
          <button className="rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800" onClick={logout}>
            Sign out
          </button>
        </div>
      </header>

      {error && (
        <div className="mb-4 rounded-lg border border-rose-900 bg-rose-950/60 p-3 text-sm text-rose-200">
          {error}
          <button className="ml-3 underline" onClick={() => setError('')}>Dismiss</button>
        </div>
      )}
      {notice && (
        <div className="mb-4 rounded-lg border border-emerald-900 bg-emerald-950/50 p-3 text-sm text-emerald-200">
          {notice}
          <button className="ml-3 underline" onClick={() => setNotice('')}>Dismiss</button>
        </div>
      )}

      {userInvitations.map((invitation) => (
        <section className="mb-4 rounded-2xl border border-cyan-900 bg-slate-900 p-5" key={invitation.id}>
          <h2 className="font-semibold text-white">Invitation to {invitation.organization_name}</h2>
          <p className="mt-1 text-sm text-slate-400">
            Invited as {invitation.role.replace('organization_', '')} · expires {new Date(invitation.expires_at).toLocaleDateString()}
          </p>
          {invitationContextRef.current.id === invitation.id ? (
            <div className="mt-3 flex gap-3">
              <button
                className={buttonClass}
                disabled={!user?.email_verified_at}
                onClick={() => respondToInvitation(invitation, 'accept')}
              >
                Accept
              </button>
              <button
                className="rounded-lg border border-slate-700 px-4 py-2 text-sm text-slate-200"
                disabled={!user?.email_verified_at}
                onClick={() => respondToInvitation(invitation, 'reject')}
              >
                Reject
              </button>
            </div>
          ) : (
            <p className="mt-2 text-xs text-slate-500">Open the secure invitation link from your email to respond.</p>
          )}
          {!user?.email_verified_at && (
            <div className="mt-2">
              <p className="text-sm text-amber-300">Verify your email address before responding.</p>
              <button className="mt-2 text-sm text-cyan-300 underline" onClick={resendVerification}>
                Resend verification email
              </button>
            </div>
          )}
        </section>
      ))}

      {organizations.length === 0 ? (
        !user?.email_verified_at ? (
          <section className="max-w-xl rounded-2xl border border-slate-800 bg-slate-900 p-6">
            <h2 className="text-lg font-semibold text-white">Verify your email to continue</h2>
            <p className="mt-2 text-sm text-slate-400">
              We sent a verification link to {user?.email}. Verify your address before creating an organization.
            </p>
            <button className={`${buttonClass} mt-4`} onClick={resendVerification}>Resend verification email</button>
          </section>
        ) : user?.can_create_organization ? (
        <section className="max-w-xl rounded-2xl border border-slate-800 bg-slate-900 p-6">
          <h2 className="text-lg font-semibold text-white">Set up your company</h2>
          <p className="mt-2 text-sm text-slate-400">Create your organization to finish onboarding. You will be its owner.</p>
          <form className="mt-4 flex gap-3" onSubmit={submitOrganization}>
            <input className={inputClass.replace('mt-1 ', '')} name="name" placeholder="Organization name" required />
            <button className={buttonClass} disabled={busy}>{busy ? 'Creating…' : 'Create Company'}</button>
          </form>
        </section>
        ) : (
          <section className="max-w-xl rounded-2xl border border-slate-800 bg-slate-900 p-6">
            <h2 className="text-lg font-semibold text-white">No active company membership</h2>
            <p className="mt-2 text-sm text-slate-400">
              Ask a company owner to restore your access or invite you to an organization. Existing company memberships are not replaced by creating another organization.
            </p>
          </section>
        )
      ) : !organizationId ? (
        <section className="max-w-xl rounded-2xl border border-slate-800 bg-slate-900 p-6">
          <h2 className="text-lg font-semibold text-white">Select an organization</h2>
          <p className="mt-2 text-sm text-slate-400">Choose one of your organization memberships to continue.</p>
          <select
            className={inputClass}
            value=""
            onChange={(event) => selectOrganization(event.target.value)}
          >
            <option value="" disabled>Select organization</option>
            {organizations.map((organization) => (
              <option key={organization.id} value={organization.id}>{organization.name}</option>
            ))}
          </select>
        </section>
      ) : (
        <>
          <nav aria-label="Company workspace" className="mb-6 flex flex-wrap gap-2">
            {workspacePages.map((page) => (
              <button
                className={`rounded-lg px-4 py-2 text-sm ${activePage === page.id ? 'bg-cyan-500 font-semibold text-slate-950' : 'border border-slate-700 text-slate-300 hover:bg-slate-800'}`}
                key={page.id}
                onClick={() => navigateWorkspace(page.id)}
              >
                {page.label}
              </button>
            ))}
          </nav>

          <section className={`mb-6 grid gap-4 ${activePage === 'dashboard' ? 'md:grid-cols-3' : 'md:grid-cols-1'}`}>
            <label className="block text-sm text-slate-300">
              Organization
              <select
                className={inputClass}
                value={organizationId}
                onChange={(event) => selectOrganization(event.target.value)}
              >
                {organizations.map((organization) => (
                  <option key={organization.id} value={organization.id}>{organization.name}</option>
                ))}
              </select>
            </label>
            {activePage === 'dashboard' && (
              <>
                <label className="block text-sm text-slate-300">
                  Project
                  <select className={inputClass} value={projectId} onChange={(event) => setProjectId(event.target.value)}>
                    <option value="">Select a project</option>
                    {orgProjects.map((project) => (
                      <option key={project.id} value={project.id}>{project.name}</option>
                    ))}
                  </select>
                </label>
                <div className="flex items-end text-sm text-slate-400">
                  {orgDatasets.length} dataset{orgDatasets.length === 1 ? '' : 's'} in this organization
                </div>
              </>
            )}
          </section>

          {activePage === 'dashboard' && (
          <>
          <section className="grid gap-6 lg:grid-cols-2">
            <div className="space-y-6">
              {canManageCompany && (
              <form className="rounded-2xl border border-slate-800 bg-slate-900 p-5" onSubmit={submitProject}>
                <h2 className="text-lg font-semibold text-white">Create project</h2>
                <label className="mt-3 block text-sm text-slate-300">
                  Project name
                  <input className={inputClass} name="name" required />
                </label>
                <label className="mt-3 block text-sm text-slate-300">
                  Description
                  <textarea className={inputClass} name="description" rows={2} />
                </label>
                <button className={`${buttonClass} mt-4`} disabled={!organizationId}>Create project</button>
              </form>
              )}

              {canManageCompany && (
              <form className="rounded-2xl border border-slate-800 bg-slate-900 p-5" onSubmit={submitDataset}>
                <h2 className="text-lg font-semibold text-white">Upload dataset</h2>
                <p className="mt-1 text-sm text-slate-400">CSV or Excel, up to 20 MB.</p>
                <label className="mt-3 block text-sm text-slate-300">
                  Dataset name (optional)
                  <input className={inputClass} name="name" />
                </label>
                <label className="mt-3 block text-sm text-slate-300">
                  File
                  <input className={inputClass} name="file" type="file" accept=".csv,.xls,.xlsx" required />
                </label>
                <button className={`${buttonClass} mt-4`} disabled={!organizationId}>Upload and analyze</button>
              </form>
              )}
              {!canManageCompany && (
                <p className="rounded-2xl border border-slate-800 bg-slate-900 p-5 text-sm text-slate-400">
                  Your member role can view company data but cannot create projects, upload datasets, or train models.
                </p>
              )}
            </div>

            <div className="space-y-6">
              {canManageCompany && (
              <form className="rounded-2xl border border-slate-800 bg-slate-900 p-5" onSubmit={submitTraining}>
                <h2 className="text-lg font-semibold text-white">Submit training job</h2>
                <p className="mt-1 text-sm text-slate-400">
                  Choose the prediction task and estimator for this dataset.
                </p>
                <label className="mt-3 block text-sm text-slate-300">
                  Dataset
                  <select
                    className={inputClass}
                    value={datasetId}
                    onChange={(event) => setDatasetId(event.target.value)}
                    required
                  >
                    <option value="">Select a dataset</option>
                    {orgDatasets.map((dataset) => (
                      <option key={dataset.id} value={dataset.id}>{dataset.name}</option>
                    ))}
                  </select>
                </label>
                <label className="mt-3 block text-sm text-slate-300">
                  Target column
                  <select
                    className={inputClass}
                    value={targetColumn}
                    onChange={(event) => setTargetColumn(event.target.value)}
                    required
                    disabled={!selectedDataset?.metadata?.columns?.length}
                  >
                    <option value="">Select target</option>
                    {(selectedDataset?.metadata?.columns ?? []).map((column) => (
                      <option key={column} value={column}>{column}</option>
                    ))}
                  </select>
                </label>
                <label className="mt-3 block text-sm text-slate-300">
                  Model name
                  <input className={inputClass} name="model_name" defaultValue="baseline-model" required />
                </label>
                <label className="mt-3 block text-sm text-slate-300">
                  Problem type
                  <select
                    className={inputClass}
                    value={problemType}
                    onChange={(event) => {
                      const nextProblemType = event.target.value === 'regression' ? 'regression' : 'classification'
                      setProblemType(nextProblemType)
                      if (!estimators[nextProblemType].some((option) => option.value === estimator)) {
                        setEstimator(estimators[nextProblemType][0].value)
                      }
                    }}
                  >
                    <option value="classification">Classification</option>
                    <option value="regression">Regression</option>
                  </select>
                </label>
                {datasetId && !selectedDataset?.metadata?.columns?.length && (
                  <p className="mt-2 text-sm text-amber-300" role="alert">
                    This dataset has no analyzed columns available for training.
                  </p>
                )}
                <label className="mt-3 block text-sm text-slate-300">
                  Estimator
                  <select
                    className={inputClass}
                    value={estimator}
                    onChange={(event) => setEstimator(event.target.value)}
                  >
                    {availableEstimators.map((option) => (
                      <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                  </select>
                </label>
                {trainingBusy && (
                  <p className="mt-3 text-sm text-cyan-200" role="status">
                    Training request is processing. Waiting for the backend result…
                  </p>
                )}
                <button
                  className={`${buttonClass} mt-4`}
                  disabled={!projectId || !datasetId || !targetColumn || trainingBusy}
                >
                  {trainingBusy ? 'Training…' : 'Submit training request'}
                </button>
                {!projectId && <p className="mt-2 text-sm text-amber-300">Create or select a project first.</p>}
              </form>
              )}

              <section className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="text-lg font-semibold text-white">Project jobs</h2>
                    <p className="text-sm text-slate-400">{orgProjects.find((project) => project.id === projectId)?.name ?? 'Select a project'}</p>
                  </div>
                  {projectId && (
                    <button
                      className="text-sm text-cyan-300 hover:text-cyan-200"
                      onClick={() => request<MlJob[]>(`/projects/${projectId}/jobs`, token).then(setJobs).catch((e: Error) => setError(e.message))}
                    >
                      Refresh
                    </button>
                  )}
                </div>
                {jobs.length === 0 ? (
                  <p className="mt-4 text-sm text-slate-400">No jobs submitted yet.</p>
                ) : (
                  <ul className="mt-4 divide-y divide-slate-800">
                    {jobs.map((job) => {
                      const hasResults = isCompletedWithResults(job)

                      return (
                        <li className="py-4" key={job.id}>
                          <div className="flex items-center justify-between gap-3">
                            <span className="font-medium text-white">{job.model_name}</span>
                            <span className={`rounded-full px-2 py-1 text-xs ${jobStatusClasses(job.status)}`}>
                              {displayJobStatus(job.status)}
                            </span>
                          </div>
                          <p className="mt-1 text-sm text-slate-400">
                            {job.job_type} · target: {job.target_column ?? 'not set'} · {new Date(job.created_at).toLocaleString()}
                          </p>
                          {job.status === 'failed' && (
                            <p className="mt-2 text-sm text-rose-300" role="alert">
                              {job.result?.message ?? 'Training failed. Check the dataset and training settings.'}
                            </p>
                          )}
                          {job.status === 'completed' && !hasResults && (
                            <p className="mt-2 text-sm text-amber-300">
                              The backend did not return valid model metrics for this job.
                            </p>
                          )}
                          {hasResults && (
                            <div className="mt-4 space-y-3">
                              <dl className="grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                  <dt className="text-slate-400">Algorithm</dt>
                                  <dd className="text-slate-100">
                                    {estimatorLabels[job.result?.estimator ?? ''] ?? job.result?.estimator ?? 'Unknown'}
                                  </dd>
                                </div>
                                <div>
                                  <dt className="text-slate-400">Problem type</dt>
                                  <dd className="capitalize text-slate-100">{job.result?.problem_type}</dd>
                                </div>
                                <div className="sm:col-span-2">
                                  <dt className="text-slate-400">Model ID</dt>
                                  <dd className="break-all font-mono text-xs text-slate-200">{job.result?.model_id}</dd>
                                </div>
                                {job.result?.artifact_path && (
                                  <div className="sm:col-span-2">
                                    <dt className="text-slate-400">Private model artifact</dt>
                                    <dd className="break-all font-mono text-xs text-slate-200">
                                      {job.result.artifact_path}
                                    </dd>
                                  </div>
                                )}
                              </dl>
                              <div>
                                <h3 className="text-sm font-medium text-slate-200">Evaluation metrics</h3>
                                <dl className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                  {Object.entries(job.result?.metrics ?? {}).map(([name, value]) => (
                                    <div className="rounded-lg border border-slate-800 bg-slate-950 p-3" key={name}>
                                      <dt className="text-xs text-slate-400">{metricLabels[name] ?? name}</dt>
                                      <dd className="mt-1 font-mono text-sm text-white">{value.toFixed(4)}</dd>
                                    </div>
                                  ))}
                                </dl>
                              </div>
                              {job.result?.message && (
                                <p className="text-xs text-slate-500">{job.result.message}</p>
                              )}
                            </div>
                          )}
                        </li>
                      )
                    })}
                  </ul>
                )}
              </section>
            </div>
          </section>

          <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 className="text-lg font-semibold text-white">Datasets</h2>
            {orgDatasets.length === 0 ? (
              <p className="mt-3 text-sm text-slate-400">No datasets uploaded yet.</p>
            ) : (
              <ul className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {orgDatasets.map((dataset) => (
                  <li className="rounded-xl border border-slate-800 bg-slate-950 p-4" key={dataset.id}>
                    <div className="flex items-center justify-between gap-3">
                      <span className="font-medium text-white">{dataset.name}</span>
                      <span className="text-xs text-emerald-300">{dataset.status}</span>
                    </div>
                    <p className="mt-2 text-sm text-slate-400">
                      {dataset.metadata?.row_count ?? 0} rows · {dataset.metadata?.column_count ?? 0} columns
                    </p>
                    <p className="mt-1 truncate text-xs text-slate-500">
                      {(dataset.metadata?.columns ?? []).join(', ') || 'No columns detected'}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </section>
          </>
          )}

          {activePage === 'predictions' && (
            <section className="space-y-6">
              <div className="grid gap-6 lg:grid-cols-2">
                <div className="space-y-6">
                  <section className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                    <h2 className="text-xl font-semibold text-white">Prediction Studio</h2>
                    <p className="mt-1 text-sm text-slate-400">
                      Run batch predictions with a completed model from this organization.
                    </p>
                    <label className="mt-4 block text-sm text-slate-300">
                      Trained model
                      <select
                        className={inputClass}
                        value={selectedModelId}
                        onChange={(event) => {
                          predictionDetailRequestRef.current += 1
                          setSelectedModelId(event.target.value)
                          setSelectedPrediction(null)
                          setPredictionDetailBusy(false)
                        }}
                        disabled={modelsBusy || predictionModels.length === 0}
                      >
                        <option value="">
                          {modelsBusy ? 'Loading models…' : predictionModels.length ? 'Select a model' : 'No completed models'}
                        </option>
                        {predictionModels.map((model) => (
                          <option key={model.id} value={model.id}>
                            {model.name} · {model.algorithm} · {model.problem_type}
                          </option>
                        ))}
                      </select>
                    </label>

                    {selectedModel && (
                      <div className="mt-4 rounded-xl border border-slate-800 bg-slate-950 p-4">
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                          <div>
                            <dt className="text-slate-400">Algorithm</dt>
                            <dd className="text-white">{estimatorLabels[selectedModel.algorithm] ?? selectedModel.algorithm}</dd>
                          </div>
                          <div>
                            <dt className="text-slate-400">Problem type</dt>
                            <dd className="capitalize text-white">{selectedModel.problem_type}</dd>
                          </div>
                          <div>
                            <dt className="text-slate-400">Target</dt>
                            <dd className="text-white">{selectedModel.target_column}</dd>
                          </div>
                          <div>
                            <dt className="text-slate-400">Trained</dt>
                            <dd className="text-white">
                              {selectedModel.trained_at ? new Date(selectedModel.trained_at).toLocaleString() : 'Unknown'}
                            </dd>
                          </div>
                          <div className="sm:col-span-2">
                            <dt className="text-slate-400">Required input features</dt>
                            <dd className="mt-1 flex flex-wrap gap-2">
                              {(selectedModel.features ?? []).map((feature) => (
                                <span className="rounded bg-slate-800 px-2 py-1 text-xs text-slate-200" key={feature.name}>
                                  {feature.name} <span className="text-slate-400">({feature.type})</span>
                                </span>
                              ))}
                            </dd>
                          </div>
                        </dl>
                      </div>
                    )}
                  </section>

                  <form className="rounded-2xl border border-slate-800 bg-slate-900 p-5" onSubmit={submitPrediction}>
                    <h3 className="font-semibold text-white">Input records</h3>
                    <label className="mt-3 block text-sm text-slate-300">
                      Run name
                      <input
                        className={inputClass}
                        value={predictionName}
                        onChange={(event) => setPredictionName(event.target.value)}
                        maxLength={255}
                        placeholder="Optional prediction run name"
                      />
                    </label>
                    <label className="mt-3 block text-sm text-slate-300">
                      CSV file (up to 20 MB)
                      <input
                        className={inputClass}
                        type="file"
                        accept=".csv,text/csv"
                        onChange={(event) => void selectPredictionFile(event.target.files?.[0] ?? null)}
                        disabled={predictionBusy}
                      />
                    </label>
                    {predictionFileError && (
                      <p className="mt-2 text-sm text-rose-300" role="alert">{predictionFileError}</p>
                    )}
                    {predictionPreview.length > 0 && (
                      <div className="mt-4 overflow-x-auto">
                        <p className="mb-2 text-xs text-slate-400">
                          Preview · {predictionFile?.name}
                        </p>
                        <table className="min-w-full text-left text-xs">
                          <thead>
                            <tr>
                              {predictionPreview[0].map((heading, index) => (
                                <th className="border-b border-slate-700 px-2 py-2 text-slate-300" key={`${heading}-${index}`}>
                                  {heading || `Column ${index + 1}`}
                                </th>
                              ))}
                            </tr>
                          </thead>
                          <tbody>
                            {predictionPreview.slice(1).map((row, rowIndex) => (
                              <tr className="border-b border-slate-800" key={rowIndex}>
                                {predictionPreview[0].map((_, columnIndex) => (
                                  <td className="max-w-48 truncate px-2 py-2 text-slate-400" key={columnIndex}>
                                    {row[columnIndex] ?? ''}
                                  </td>
                                ))}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                        {selectedModel && missingPredictionFeatures.length > 0 && (
                          <p className="mt-2 text-sm text-rose-300" role="alert">
                            Missing required feature columns:{' '}
                            {missingPredictionFeatures.map((feature) => feature.name).join(', ')}
                          </p>
                        )}
                      </div>
                    )}
                    {predictionBusy && (
                      <p className="mt-3 text-sm text-cyan-200" role="status">
                        Processing prediction batch…
                      </p>
                    )}
                    <button
                      className={`${buttonClass} mt-4`}
                      disabled={
                        !selectedModel ||
                        !predictionFile ||
                        missingPredictionFeatures.length > 0 ||
                        predictionBusy ||
                        Boolean(predictionFileError) ||
                        predictionPreview.length < 2
                      }
                    >
                      {predictionBusy ? 'Predicting…' : 'Run predictions'}
                    </button>
                  </form>
                </div>

                <section className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <h3 className="text-lg font-semibold text-white">Prediction history</h3>
                      <p className="text-sm text-slate-400">Runs saved for this organization.</p>
                    </div>
                    <button
                      className="text-sm text-cyan-300 hover:text-cyan-200"
                      onClick={() => void refreshPredictionHistory().catch((e: Error) => setError(e.message))}
                    >
                      Refresh
                    </button>
                  </div>
                  {predictionRuns.length === 0 ? (
                    <p className="mt-4 text-sm text-slate-400">
                      {modelsBusy ? 'Loading prediction history…' : 'No prediction runs yet.'}
                    </p>
                  ) : (
                    <ul className="mt-4 divide-y divide-slate-800">
                      {predictionRuns.map((run) => (
                        <li className="py-3" key={run.id}>
                          <button
                            className="w-full text-left"
                            onClick={() => void openPredictionRun(run)}
                          >
                            <span className="flex items-center justify-between gap-3">
                              <span className="font-medium text-white">{run.name}</span>
                              <span className={`rounded-full px-2 py-1 text-xs ${jobStatusClasses(run.status)}`}>
                                {displayJobStatus(run.status)}
                              </span>
                            </span>
                            <span className="mt-1 block text-xs text-slate-400">
                              {run.model_name ?? run.model_id} · {run.input_filename} · {run.row_count ?? '—'} rows
                            </span>
                            <span className="mt-1 block text-xs text-slate-500">
                              Created {new Date(run.created_at).toLocaleString()}
                              {run.completed_at ? ` · completed ${new Date(run.completed_at).toLocaleString()}` : ''}
                            </span>
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              </div>

              {selectedPrediction && (
                <section className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <h3 className="text-lg font-semibold text-white">{selectedPrediction.prediction_run.name}</h3>
                      <p className="mt-1 text-sm text-slate-400">
                        {selectedPrediction.prediction_run.model_name} · {selectedPrediction.prediction_run.input_filename}
                      </p>
                    </div>
                    {selectedPrediction.prediction_run.status === 'completed' && (
                      <button
                        className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-cyan-300 disabled:opacity-50"
                        disabled={exportBusy}
                        onClick={() => void exportPrediction(selectedPrediction.prediction_run)}
                      >
                        {exportBusy ? 'Preparing CSV…' : 'Export CSV'}
                      </button>
                    )}
                  </div>
                  {selectedPrediction.prediction_run.status === 'failed' && (
                    <p className="mt-3 text-sm text-rose-300" role="alert">
                      {selectedPrediction.prediction_run.error_message ?? 'Prediction failed.'}
                    </p>
                  )}
                  {predictionDetailBusy && (
                    <p className="mt-3 text-sm text-cyan-200" role="status">Loading saved results…</p>
                  )}
                  {selectedPrediction.results && (
                    <>
                      <p className="mt-3 text-sm text-slate-400">
                        {selectedPrediction.prediction_run.row_count} actual predictions ·{' '}
                        {selectedPrediction.results.metadata.problem_type} · target {selectedPrediction.results.metadata.target_column}
                      </p>
                      <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                          <thead>
                            <tr className="border-b border-slate-700 text-xs uppercase text-slate-400">
                              <th className="px-3 py-2">Row</th>
                              <th className="px-3 py-2">Prediction</th>
                              {selectedPrediction.results.metadata.supports_probabilities && (
                                <th className="px-3 py-2">Class probabilities</th>
                              )}
                            </tr>
                          </thead>
                          <tbody>
                            {selectedPrediction.results.predictions.slice(0, 100).map((row) => (
                              <tr className="border-b border-slate-800" key={row.row}>
                                <td className="px-3 py-2 text-slate-400">{row.row}</td>
                                <td className="px-3 py-2 font-mono text-white">
                                  {typeof row.prediction === 'number' ? row.prediction.toFixed(6) : String(row.prediction)}
                                </td>
                                {selectedPrediction.results?.metadata.supports_probabilities && (
                                  <td className="px-3 py-2 text-slate-300">
                                    {(row.probabilities ?? [])
                                      .map((item) => `${String(item.class)}: ${item.probability.toFixed(4)}`)
                                      .join(' · ')}
                                  </td>
                                )}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                        {selectedPrediction.results.predictions.length > 100 && (
                          <p className="mt-2 text-xs text-slate-500">
                            Showing the first 100 rows. Export the CSV to access the complete batch.
                          </p>
                        )}
                      </div>
                    </>
                  )}
                </section>
              )}
            </section>
          )}

          {activePage === 'company' && activeOrganization && (
            <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
              <h2 className="text-xl font-semibold text-white">Company Profile</h2>
              <p className="mt-1 text-sm text-slate-400">
                Created {new Date(activeOrganization.created_at).toLocaleDateString()} · {activeOrganization.timezone || 'UTC'}
              </p>
              {canManageCompany ? (
                <form className="mt-5 grid gap-4 md:grid-cols-2" key={organizationId} onSubmit={submitCompanyProfile}>
                  <label className="text-sm text-slate-300">
                    Company name
                    <input className={inputClass} name="name" defaultValue={activeOrganization.name} required />
                  </label>
                  <label className="text-sm text-slate-300">
                    Logo URL
                    <input className={inputClass} name="logo_url" type="url" defaultValue={activeOrganization.logo_url ?? ''} />
                  </label>
                  <label className="text-sm text-slate-300">
                    Industry
                    <input className={inputClass} name="industry" defaultValue={activeOrganization.industry ?? ''} />
                  </label>
                  <label className="text-sm text-slate-300">
                    Contact email
                    <input className={inputClass} name="contact_email" type="email" defaultValue={activeOrganization.contact_email ?? ''} />
                  </label>
                  <label className="text-sm text-slate-300">
                    Phone
                    <input className={inputClass} name="phone" defaultValue={activeOrganization.phone ?? ''} />
                  </label>
                  <label className="text-sm text-slate-300">
                    Timezone
                    <input className={inputClass} name="timezone" defaultValue={activeOrganization.timezone || 'UTC'} required />
                  </label>
                  <label className="text-sm text-slate-300 md:col-span-2">
                    Address
                    <input className={inputClass} name="address" defaultValue={activeOrganization.address ?? ''} />
                  </label>
                  <label className="text-sm text-slate-300 md:col-span-2">
                    Description
                    <textarea className={inputClass} name="description" rows={4} defaultValue={activeOrganization.description ?? ''} />
                  </label>
                  <div className="md:col-span-2">
                    <button className={buttonClass}>Save company profile</button>
                  </div>
                </form>
              ) : (
                <dl className="mt-5 grid gap-4 text-sm md:grid-cols-2">
                  {activeOrganization.logo_url && (
                    <div className="md:col-span-2">
                      <dt className="text-slate-400">Logo</dt>
                      <dd className="mt-2"><img className="max-h-20 max-w-48 rounded" src={activeOrganization.logo_url} alt={`${activeOrganization.name} logo`} referrerPolicy="no-referrer" /></dd>
                    </div>
                  )}
                  {[
                    ['Industry', activeOrganization.industry],
                    ['Contact email', activeOrganization.contact_email],
                    ['Phone', activeOrganization.phone],
                    ['Address', activeOrganization.address],
                    ['Description', activeOrganization.description],
                  ].map(([label, value]) => (
                    <div key={label}>
                      <dt className="text-slate-400">{label}</dt>
                      <dd className="mt-1 whitespace-pre-wrap text-slate-100">{value || 'Not provided'}</dd>
                    </div>
                  ))}
                </dl>
              )}
            </section>
          )}

          {activePage === 'users' && (
            <section className="space-y-6">
              <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
                <h2 className="text-xl font-semibold text-white">Company Users</h2>
                <p className="mt-1 text-sm text-slate-400">Only members of this company are listed here.</p>
                <ul className="mt-4 divide-y divide-slate-800">
                  {members.map((member) => (
                    <li className="flex flex-wrap items-center justify-between gap-3 py-4" key={member.id}>
                      <div>
                        <p className="font-medium text-white">{member.name}</p>
                        <p className="text-sm text-slate-400">{member.email}</p>
                        <p className="mt-1 text-xs capitalize text-slate-500">
                          {member.role.replace('organization_', '').replace('_', ' ')} · {member.status}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        {canManageCompany && member.status === 'active' && member.role !== 'organization_owner' &&
                          (isOrganizationOwner || member.role === 'organization_admin') && (
                            <select
                              className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                              value={member.role}
                              aria-label={`Role for ${member.name}`}
                              onChange={(event) => updateMemberRole(member, event.target.value)}
                            >
                              {isOrganizationOwner && <option value="organization_owner">Owner</option>}
                              {(isOrganizationOwner || member.role === 'organization_admin') &&
                                <option value="organization_admin">Administrator</option>}
                              <option value="organization_member">Member</option>
                            </select>
                          )}
                        {canManageCompany && member.status === 'active' && member.role !== 'organization_owner' &&
                          (isOrganizationOwner || member.role === 'organization_member') && (
                            <button
                              className="rounded-lg border border-rose-900 px-3 py-2 text-sm text-rose-300"
                              onClick={() => revokeMember(member)}
                            >
                              Revoke access
                            </button>
                          )}
                      </div>
                    </li>
                  ))}
                </ul>
              </div>

              {canManageCompany && (
                <div className="grid gap-6 lg:grid-cols-2">
                  <form className="rounded-2xl border border-slate-800 bg-slate-900 p-6" onSubmit={submitInvitation}>
                    <h3 className="font-semibold text-white">Invite a user</h3>
                    <label className="mt-3 block text-sm text-slate-300">
                      Email
                      <input className={inputClass} name="email" type="email" required />
                    </label>
                    <label className="mt-3 block text-sm text-slate-300">
                      Role
                      <select className={inputClass} name="role" defaultValue="organization_member">
                        <option value="organization_member">Member</option>
                        {isOrganizationOwner && <option value="organization_admin">Administrator</option>}
                      </select>
                    </label>
                    <button className={`${buttonClass} mt-4`}>Send invitation</button>
                  </form>
                  <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
                    <h3 className="font-semibold text-white">Pending invitations</h3>
                    {organizationInvitations.length === 0 ? (
                      <p className="mt-3 text-sm text-slate-400">No pending invitations.</p>
                    ) : (
                      <ul className="mt-3 divide-y divide-slate-800">
                        {organizationInvitations.map((invitation) => (
                          <li className="flex items-center justify-between gap-3 py-3" key={invitation.id}>
                            <div>
                              <p className="text-sm text-white">{invitation.email}</p>
                              <p className="text-xs capitalize text-slate-400">
                                {invitation.role.replace('organization_', '').replace('_', ' ')}
                              </p>
                            </div>
                            <button className="text-sm text-rose-300" onClick={() => revokeInvitation(invitation)}>
                              Revoke
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                </div>
              )}
            </section>
          )}

          {activePage === 'permissions' && (
            <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
              <h2 className="text-xl font-semibold text-white">Roles & Permissions</h2>
              <p className="mt-1 text-sm text-slate-400">
                Your role: <span className="capitalize text-slate-200">{companyPermissions?.current_role.replace('organization_', '').replace('_', ' ') ?? activeOrganization?.role}</span>
              </p>
              <div className="mt-5 grid gap-4 md:grid-cols-3">
                {(companyPermissions?.roles ?? []).map((role) => (
                  <article className="rounded-xl border border-slate-800 bg-slate-950 p-4" key={role.role}>
                    <h3 className="font-semibold capitalize text-white">{role.role.replace('organization_', '').replace('_', ' ')}</h3>
                    <ul className="mt-3 space-y-1 text-sm text-slate-400">
                      {role.permissions.map((permission) => (
                        <li className="capitalize" key={permission}>{permission.replace(/_/g, ' ')}</li>
                      ))}
                    </ul>
                  </article>
                ))}
              </div>
            </section>
          )}

          {activePage === 'settings' && activeOrganization && (
            <section className="max-w-2xl rounded-2xl border border-slate-800 bg-slate-900 p-6">
              <h2 className="text-xl font-semibold text-white">Company Settings</h2>
              <p className="mt-2 text-sm text-slate-400">
                Organization owners control company registration settings. Current status: {activeOrganization.status}.
              </p>
              {isOrganizationOwner ? (
                <form className="mt-5" key={`${organizationId}-${activeOrganization.registration_policy}`} onSubmit={submitCompanySettings}>
                  <label className="block text-sm text-slate-300">
                    Registration policy
                    <select className={inputClass} name="registration_policy" defaultValue={activeOrganization.registration_policy}>
                      <option value="open">Open</option>
                      <option value="admin_approval">Administrator approval</option>
                      <option value="invite_only">Invite only</option>
                    </select>
                  </label>
                  <button className={`${buttonClass} mt-4`}>Save settings</button>
                </form>
              ) : (
                <p className="mt-4 text-sm text-slate-400">Only the organization owner can change these settings.</p>
              )}
            </section>
          )}
        </>
      )}
    </main>
  )
}

export default App

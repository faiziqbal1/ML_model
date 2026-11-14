import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

const overview = [
  { label: 'Organizations', value: '128', delta: '+12%' },
  { label: 'Active datasets', value: '1,482', delta: '+8.4%' },
  { label: 'ML jobs queued', value: '94', delta: '-3.1%' },
  { label: 'Model accuracy avg', value: '92.4%', delta: '+2.9%' },
]

const usageByMonth = [
  { month: 'Jan', usage: 40 },
  { month: 'Feb', usage: 52 },
  { month: 'Mar', usage: 48 },
  { month: 'Apr', usage: 70 },
  { month: 'May', usage: 68 },
  { month: 'Jun', usage: 84 },
  { month: 'Jul', usage: 96 },
]

const modelMix = [
  { name: 'Regression', value: 42 },
  { name: 'Classification', value: 31 },
  { name: 'Forecasting', value: 18 },
  { name: 'Segmentation', value: 9 },
]

const capabilities = [
  'Dataset upload and schema validation',
  'Organization-level RBAC and approval flow',
  'Model training, evaluation, and artifact tracking',
  'Prediction history and business reporting',
]

function App() {
  return (
    <div className="min-h-screen bg-slate-950 text-slate-100">
      <div className="mx-auto max-w-7xl px-6 py-10">
        <header className="mb-10 flex items-center justify-between border-b border-slate-800 pb-6">
          <div>
            <p className="text-sm uppercase tracking-[0.25em] text-cyan-400">ModelForge</p>
            <h1 className="mt-2 text-3xl font-semibold text-white">Multi-tenant ML operations</h1>
          </div>
          <button className="rounded-full border border-cyan-400/60 bg-cyan-500/10 px-4 py-2 text-sm font-medium text-cyan-200 transition hover:bg-cyan-500/20">
            Launch workspace
          </button>
        </header>

        <section className="grid gap-4 md:grid-cols-4">
          {overview.map((stat) => (
            <div key={stat.label} className="rounded-2xl border border-slate-800 bg-slate-900 p-5 shadow-sm shadow-slate-950/30">
              <p className="text-sm text-slate-400">{stat.label}</p>
              <div className="mt-4 flex items-end justify-between">
                <span className="text-3xl font-semibold text-white">{stat.value}</span>
                <span className="text-sm font-medium text-emerald-400">{stat.delta}</span>
              </div>
            </div>
          ))}
        </section>

        <section className="mt-8 grid gap-6 lg:grid-cols-[1.5fr_0.9fr]">
          <div className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-semibold text-white">Platform utilization</h2>
              <span className="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-300">+18.2% QoQ</span>
            </div>
            <div className="h-72">
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={usageByMonth}>
                  <defs>
                    <linearGradient id="usageFill" x1="0" x2="0" y1="0" y2="1">
                      <stop offset="0%" stopColor="#22d3ee" stopOpacity={0.75} />
                      <stop offset="100%" stopColor="#22d3ee" stopOpacity={0.04} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid stroke="#334155" strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="month" stroke="#94a3b8" />
                  <YAxis stroke="#94a3b8" />
                  <Tooltip />
                  <Area dataKey="usage" stroke="#22d3ee" strokeWidth={3} fill="url(#usageFill)" />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          </div>

          <div className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 className="text-lg font-semibold text-white">Model mix</h2>
            <div className="mt-6 h-72">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={modelMix}>
                  <CartesianGrid vertical={false} stroke="#334155" />
                  <XAxis dataKey="name" stroke="#94a3b8" />
                  <YAxis stroke="#94a3b8" />
                  <Tooltip />
                  <Bar dataKey="value" fill="#a78bfa" radius={[8, 8, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>
        </section>

        <section className="mt-8 grid gap-6 lg:grid-cols-[1fr_1.1fr]">
          <div className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 className="text-lg font-semibold text-white">Core capabilities</h2>
            <ul className="mt-5 space-y-4">
              {capabilities.map((item) => (
                <li key={item} className="flex items-start gap-3 text-slate-200">
                  <span className="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-cyan-500/15 text-xs text-cyan-300">✓</span>
                  <span>{item}</span>
                </li>
              ))}
            </ul>
          </div>

          <div className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 className="text-lg font-semibold text-white">Operational checklist</h2>
            <div className="mt-5 space-y-4">
              {[
                ['Organization approvals', 'Required policy gate enabled'],
                ['Subscription controls', 'Trial, basic, advanced modes mapped'],
                ['Dataset validation', 'Schema checks and file hashing active'],
                ['ML training queue', 'Redis-backed scheduling ready'],
              ].map(([title, value]) => (
                <div key={title} className="rounded-xl border border-slate-700 bg-slate-950/50 p-3">
                  <div className="flex items-center justify-between">
                    <p className="font-medium text-white">{title}</p>
                    <span className="rounded-full bg-emerald-500/10 px-2 py-1 text-[11px] font-medium text-emerald-300">Ready</span>
                  </div>
                  <p className="mt-2 text-sm text-slate-400">{value}</p>
                </div>
              ))}
            </div>
          </div>
        </section>
      </div>
    </div>
  )
}

export default App

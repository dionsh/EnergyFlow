import { useState } from 'react'
import { api } from '../../lib/api'

export function CreateCompany({ onCreated }) {
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  async function submit(event) {
    event.preventDefault()
    const form = event.currentTarget
    setBusy(true)
    setMessage('')
    try {
      await api.post('/admin/companies', Object.fromEntries(new FormData(form)))
      form.reset()
      setMessage('Company and owner created. The owner can now sign in and add workers in Settings.')
      onCreated()
    } catch (error) {
      setMessage(error.fields?.email === 'email_taken' ? 'This email already has an account.' : error.message)
    } finally { setBusy(false) }
  }
  return <form onSubmit={submit} className="mb-6 grid gap-3 rounded-xl border border-line p-4">
    <h2 className="font-semibold">Add company and owner</h2>
    {[['company_name', 'Company name'], ['full_name', 'Owner name'], ['email', 'Owner email'], ['password', 'Owner password']].map(([name, label]) => <label key={name}>{label}<input className="block w-full rounded border p-2" name={name} type={name === 'password' ? 'password' : name === 'email' ? 'email' : 'text'} required minLength={name === 'password' ? 12 : 2} maxLength={name === 'password' ? 200 : name === 'email' ? 190 : name === 'full_name' ? 120 : 160} autoComplete={name === 'password' ? 'new-password' : 'off'} /></label>)}
    <button className="rounded border border-line p-2" disabled={busy}>{busy ? 'Creating…' : 'Create company owner'}</button>
    {message && <p role="status">{message}</p>}
  </form>
}

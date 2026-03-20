import { Controller } from '@hotwired/stimulus'

/**
 * pipeline-actions controller
 *
 * Wires the "Run" / "Re-run" buttons in <twig:PipelineActions> to the
 * image_run_task and image_enqueue_pipeline endpoints.
 *
 * No HTML is generated in JS — on success it reloads the component via
 * the image_show endpoint so the Twig-rendered component reflects the
 * latest state.
 *
 * Values:
 *   taskRouteValue      — Symfony route name for POST /task/{taskName}
 *   pipelineRouteValue  — Symfony route name for POST /pipeline/{pipelineName}
 *   routeParamsValue    — JSON object of base route params (e.g. {imageId: '...'})
 */
export default class extends Controller {
    static values = {
        taskRoute:     { type: String, default: '' },
        pipelineRoute: { type: String, default: '' },
        routeParams:   { type: Object, default: {} },
    }

    static targets = ['taskLog', 'taskLogEntries']

    // ── Actions ────────────────────────────────────────────────────────────────

    async runTask(event) {
        const btn      = event.currentTarget
        const taskName = btn.dataset.pipelineActionsTaskParam
        if (!taskName || !this.taskRouteValue) return

        await this._post(
            this._buildUrl(this.taskRouteValue, { taskName }),
            btn,
            `Running ${taskName}…`
        )
    }

    async runPipeline(event) {
        const btn          = event.currentTarget
        const pipelineName = btn.dataset.pipelineActionsPipelineParam
        if (!pipelineName || !this.pipelineRouteValue) return

        await this._post(
            this._buildUrl(this.pipelineRouteValue, { pipelineName }),
            btn,
            `Running pipeline ${pipelineName}…`
        )
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * POST to url, show spinner on btn, reload the show page section on success.
     * All HTML is rendered server-side — we never generate HTML here.
     */
    async _post(url, btn, label) {
        if (!url) {
            console.warn('[pipeline-actions] no URL, skipping', { url, label })
            return
        }

        const originalText = btn.innerHTML
        btn.disabled = true
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> ${label}`

        try {
            const res  = await fetch(url, {
                method:  'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            })
            const data = await res.json()

            if (!res.ok || !data.ok) {
                this._logEntry(label, data.error ?? 'Failed', 'danger')
                btn.innerHTML  = '✖ Failed'
                btn.classList.replace('btn-outline-primary', 'btn-outline-danger')
                setTimeout(() => {
                    btn.innerHTML = originalText
                    btn.classList.replace('btn-outline-danger', 'btn-outline-primary')
                    btn.disabled = false
                }, 3000)
                return
            }

            this._logEntry(label, `Done — marking: ${data.marking}`, 'success')

            // Reload the full show page so the Twig component re-renders with fresh data.
            // Turbo handles partial replacement if a Turbo Frame wraps the card.
            window.location.reload()

        } catch (err) {
            console.error('[pipeline-actions]', err)
            this._logEntry(label, err.message, 'danger')
            btn.innerHTML = originalText
            btn.disabled  = false
        }
    }

    _buildUrl(routeName, extraParams) {
        if (!routeName) return null
        // Use Symfony's Routing component exposed via FOSJsRoutingBundle / expose=true
        if (typeof Routing !== 'undefined') {
            return Routing.generate(routeName, { ...this.routeParamsValue, ...extraParams })
        }
        // Fallback: reconstruct from current path
        const base = window.location.pathname.replace(/\/$/, '')
        if (extraParams.taskName)     return `${base}/task/${extraParams.taskName}`
        if (extraParams.pipelineName) return `${base}/pipeline/${extraParams.pipelineName}`
        return null
    }

    _logEntry(label, message, type = 'secondary') {
        const log = document.getElementById('pipeline-task-log')
        const entries = document.getElementById('pipeline-task-log-entries')
        if (!log || !entries) return

        log.style.display = ''
        const div = document.createElement('div')
        div.className = `small text-${type} py-1 border-bottom`
        div.textContent = `${label}: ${message}`
        entries.prepend(div)
    }
}

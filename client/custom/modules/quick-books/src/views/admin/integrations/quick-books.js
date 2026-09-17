import IntegrationsEditView from 'views/admin/integrations/edit';

/**
 * QuickBooks integration admin view.
 * Extends the standard edit form with an OAuth Connect button,
 * a Sync Now button, and last-sync-error display.
 */
export default class QuickBooksIntegrationView extends IntegrationsEditView {

    events = {
        /** @this QuickBooksIntegrationView */
        'click [data-action="connectQuickBooks"]': function () {
            this.actionConnectQuickBooks();
        },
        /** @this QuickBooksIntegrationView */
        'click [data-action="runSync"]': function () {
            this.actionRunSync();
        },
    }

    afterRender() {
        super.afterRender();
        this.renderStatusSection();
    }

    renderStatusSection() {
        const isConnected = !!this.model.get('realmId');
        const realmId = this.model.get('realmId') ?? '';
        const connectedAt = this.model.get('connectedAt') ?? '';
        const lastSyncAt = this.model.get('lastSyncAt') ?? '';
        const lastSyncError = this.model.get('lastSyncError') ?? '';
        const btnLabel = isConnected ? 'Reconnect to QuickBooks' : 'Connect to QuickBooks';

        const connectionHtml = isConnected
            ? `<span style="color:#2b7de9;font-weight:600">&#10003; Connected</span>
               <span style="color:#666;margin-left:10px;font-size:12px">Realm: ${realmId}</span>
               <span style="color:#999;margin-left:8px;font-size:12px">${connectedAt}</span>`
            : `<span style="color:#999">Not connected to QuickBooks</span>`;

        const syncStatusHtml = lastSyncAt
            ? `<div style="margin-top:8px;font-size:12px;color:#666">Last sync: ${lastSyncAt}</div>`
            : '';

        const errorHtml = lastSyncError
            ? `<div style="margin-top:8px;padding:8px 10px;background:#fff3f3;border:1px solid #f5c6c6;
                           border-radius:4px;font-size:12px;color:#a33;word-break:break-word;">
                 <strong>Sync error:</strong> ${lastSyncError}
               </div>`
            : '';

        const html = `
            <div class="qb-status-wrap" style="
                margin-top: 20px;
                padding: 16px 18px;
                background: #f7f9fc;
                border: 1px solid #dce1ea;
                border-radius: 6px;
            ">
                <div style="margin-bottom:12px;font-size:13px">${connectionHtml}</div>
                ${syncStatusHtml}
                ${errorHtml}
                <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn btn-primary btn-sm" data-action="connectQuickBooks">
                        ${btnLabel}
                    </button>
                    ${isConnected ? `<button class="btn btn-default btn-sm" data-action="runSync">
                        Sync Now
                    </button>` : ''}
                </div>
                <p style="margin-top:10px;margin-bottom:0;font-size:12px;color:#888">
                    Save your Client ID and Client Secret first, then click Connect.
                    Sync Now runs pull + reconcile immediately; may take a moment on large datasets.
                </p>
            </div>
        `;

        this.$el.find('.panel-body').last().append(html);
    }

    actionConnectQuickBooks() {
        const clientId = this.model.get('clientId');

        if (!clientId) {
            Espo.Ui.warning('Save your Client ID and Client Secret first.');
            return;
        }

        const siteUrl = (this.getConfig().get('siteUrl') ?? window.location.origin).replace(/\/$/, '');

        Espo.Ajax.postRequest('QuickBooksIntegration/initOAuth', {})
            .then(data => this.openOAuthPopup(clientId, siteUrl, data.state))
            .catch(() => Espo.Ui.error('Could not initiate QuickBooks OAuth. Check server logs.'));
    }

    openOAuthPopup(clientId, siteUrl, state) {
        const redirectUri = `${siteUrl}/quickbooks/callback`;

        const authUrl =
            'https://appcenter.intuit.com/connect/oauth2' +
            '?client_id=' + encodeURIComponent(clientId) +
            '&scope=com.intuit.quickbooks.accounting' +
            '&redirect_uri=' + encodeURIComponent(redirectUri) +
            '&response_type=code' +
            '&state=' + encodeURIComponent(state);

        const popup = window.open(authUrl, 'qb-oauth', 'width=650,height=720,left=200,top=100');

        if (!popup) {
            Espo.Ui.error('Pop-up was blocked. Please allow pop-ups for this site and try again.');
            return;
        }

        const onMessage = (event) => {
            if (event.origin !== siteUrl) return;
            if (!event.data || event.data.name !== 'quickBooksOAuth') return;

            window.removeEventListener('message', onMessage);

            if (event.data.status === 'success') {
                Espo.Ui.success('QuickBooks connected successfully.');
                this.model.fetch().then(() => {
                    this.$el.find('.qb-status-wrap').remove();
                    this.renderStatusSection();
                });
            } else {
                Espo.Ui.error('QuickBooks connection failed. Check the browser console for details.');
            }
        };

        window.addEventListener('message', onMessage);

        const pollClosed = setInterval(() => {
            if (popup.closed) {
                clearInterval(pollClosed);
                window.removeEventListener('message', onMessage);
            }
        }, 800);
    }

    actionRunSync() {
        const $btn = this.$el.find('[data-action="runSync"]');
        $btn.prop('disabled', true).text('Syncing…');

        Espo.Ajax.postRequest('QuickBooksIntegration/runSync', {})
            .then(() => {
                Espo.Ui.success('Sync completed.');
                this.model.fetch().then(() => {
                    this.$el.find('.qb-status-wrap').remove();
                    this.renderStatusSection();
                });
            })
            .catch(() => {
                Espo.Ui.error('Sync failed. Check Admin → Log for details.');
                $btn.prop('disabled', false).text('Sync Now');
            });
    }
}

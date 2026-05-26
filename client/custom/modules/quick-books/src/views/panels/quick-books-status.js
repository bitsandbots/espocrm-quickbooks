import View from 'view';

/**
 * Side panel shown on Account and Contact detail views.
 * Displays QB sync status fields in read-only mode.
 */
export default class QuickBooksStatusPanelView extends View {

    template = 'quick-books:panels/quick-books-status'

    data() {
        const model = this.model;
        const qbId = model.get('qbCustomerId');
        const syncedAt = model.get('qbSyncedAt');

        return {
            isLinked: !!qbId,
            qbCustomerId: qbId ?? '—',
            qbSyncedAt: syncedAt
                ? new Date(syncedAt).toLocaleString()
                : '—',
            qbCustomerUrl: qbId
                ? `https://app.qbo.intuit.com/app/customerdetail?nameId=${qbId}`
                : null,
        };
    }
}

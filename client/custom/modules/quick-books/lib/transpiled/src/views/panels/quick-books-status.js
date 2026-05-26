define("modules/quick-books/views/panels/quick-books-status", ["exports", "view"], function (_exports, _view) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.default = void 0;
  _view = _interopRequireDefault(_view);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /**
   * Side panel shown on Account and Contact detail views.
   * Displays QB sync status fields in read-only mode.
   */
  class QuickBooksStatusPanelView extends _view.default {
    template = 'quick-books:panels/quick-books-status';
    data() {
      const model = this.model;
      const qbId = model.get('qbCustomerId');
      const syncedAt = model.get('qbSyncedAt');
      return {
        isLinked: !!qbId,
        qbCustomerId: qbId ?? '—',
        qbSyncedAt: syncedAt ? new Date(syncedAt).toLocaleString() : '—',
        qbCustomerUrl: qbId ? `https://app.qbo.intuit.com/app/customerdetail?nameId=${qbId}` : null
      };
    }
  }
  _exports.default = QuickBooksStatusPanelView;
});
//# sourceMappingURL=quick-books-status.js.map ;
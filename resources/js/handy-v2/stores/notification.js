export function createNotificationStore() {
    return {
        show: false,
        message: '',
        type: 'info',
        _timer: null,

        success(message, duration = 3000) {
            this._show(message, 'success', duration);
        },

        error(message, duration = 5000) {
            this._show(message, 'error', duration);
        },

        warning(message, duration = 4000) {
            this._show(message, 'warning', duration);
        },

        info(message, duration = 3000) {
            this._show(message, 'info', duration);
        },

        _show(message, type, duration) {
            if (this._timer) clearTimeout(this._timer);
            this.message = message;
            this.type = type;
            this.show = true;
            if (duration > 0) {
                this._timer = setTimeout(() => {
                    this.show = false;
                }, duration);
            }
        },

        dismiss() {
            if (this._timer) clearTimeout(this._timer);
            this.show = false;
        },
    };
}

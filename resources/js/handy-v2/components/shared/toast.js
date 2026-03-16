/**
 * Toast notification component
 * Uses notification store for state management.
 * Usage: Include the toast partial in layout, it auto-connects to Alpine store.
 */
export function toastComponent() {
    return {
        // Component is purely template-driven, no additional logic needed.
        // Reads from $store.notification directly in Blade template.
    };
}

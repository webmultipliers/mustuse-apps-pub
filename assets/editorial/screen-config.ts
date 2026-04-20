/**
 * Screen-routing metabox: swap visible sub-fields when the role or
 * content-source dropdowns change.
 *
 * Two dropdowns, three roles. The Role picker (`#mua_screen_role`)
 * decides which top-level section is visible:
 *   static  — no extra fields
 *   archive — show the Content Source row + its conditional children
 *   detail  — show the URL Pattern row only
 *
 * Within `archive`, the Content Source picker (`#mua_route_type`)
 * further toggles between standard (post type + term) and custom
 * (registered PHP provider) sub-fields.
 *
 * The metabox ships every field in the DOM; we just flip `hidden`.
 */

export function initScreenConfig(root: ParentNode = document): void {
    const roleSelect  = root.querySelector<HTMLSelectElement>('#mua_screen_role');
    const routeSelect = root.querySelector<HTMLSelectElement>('#mua_route_type');
    if (!roleSelect && !routeSelect) return;

    const apply = (): void => {
        // If the page doesn't render a role picker, treat the whole screen
        // as archive-style (legacy Phase 24 layout with just the Content
        // Source dropdown). That keeps existing tests and pre-Phase-E
        // screens working without a DOM change.
        const role = roleSelect ? roleSelect.value : 'archive';

        root.querySelectorAll<HTMLElement>('.mua-screen-role-fields--detail').forEach((el) => {
            el.hidden = role !== 'detail';
        });
        root.querySelectorAll<HTMLElement>('.mua-screen-role-fields--archive').forEach((el) => {
            el.hidden = role !== 'archive';
        });

        // Content Source branches are only meaningful in archive role.
        const activeRouteType = role === 'archive' ? (routeSelect?.value ?? 'none') : '';
        root.querySelectorAll<HTMLElement>('.mua-screen-route-fields--standard').forEach((el) => {
            el.hidden = activeRouteType !== 'standard';
        });
        root.querySelectorAll<HTMLElement>('.mua-screen-route-fields--custom').forEach((el) => {
            el.hidden = activeRouteType !== 'custom';
        });
    };

    roleSelect?.addEventListener('change', apply);
    routeSelect?.addEventListener('change', apply);
    apply();
}

/**
 * Turns a validated locator descriptor into a Playwright Locator.
 *
 * Only the `getBy*` family and `locator()` are reachable from here, and only
 * with values that already passed schema validation — there is no path from a
 * stored recipe to an arbitrary Playwright method.
 */

/**
 * @param {import("playwright-core").Page} page
 * @param {object} descriptor
 */
export function buildLocator(page, descriptor) {
    const { strategy, exact, nth } = descriptor;
    let locator;

    switch (strategy) {
        case "role":
            locator = page.getByRole(
                descriptor.role,
                descriptor.name === undefined
                    ? undefined
                    : { name: descriptor.name, ...(exact === undefined ? {} : { exact }) },
            );
            break;
        case "label":
            locator = page.getByLabel(descriptor.label, exact === undefined ? undefined : { exact });
            break;
        case "text":
            locator = page.getByText(descriptor.text, exact === undefined ? undefined : { exact });
            break;
        case "placeholder":
            locator = page.getByPlaceholder(
                descriptor.placeholder,
                exact === undefined ? undefined : { exact },
            );
            break;
        case "testId":
            locator = page.getByTestId(descriptor.testId);
            break;
        case "css":
            locator = page.locator(descriptor.css);
            break;
        default:
            // validateRecipe already rejected anything else; this is a guard
            // against a future strategy being added without a case here.
            throw new Error(`Unsupported locator strategy "${strategy}".`);
    }

    return typeof nth === "number" ? locator.nth(nth) : locator;
}

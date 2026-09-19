"use client";

import { ArrowDown, ArrowUp, Trash2 } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { RecipeAction, RecipeLocator } from "@/lib/scrapper/types";

/** Mirrors RecipeValidator::describeLocator on the API side. */
export function describeLocator(locator?: RecipeLocator): string {
    if (!locator) return "page";
    const suffix = locator.hasText ? ` containing "${locator.hasText}"` : "";
    return describeLocatorBase(locator) + suffix;
}

function describeLocatorBase(locator: RecipeLocator): string {
    switch (locator.strategy) {
        case "role":
            return locator.name ? `role=${locator.role} "${locator.name}"` : `role=${locator.role}`;
        case "label":
            return `label "${locator.label}"`;
        case "text":
            return `text "${locator.text}"`;
        case "placeholder":
            return `placeholder "${locator.placeholder}"`;
        case "testId":
            return `test id "${locator.testId}"`;
        case "css":
            return `css "${locator.css}"`;
        default:
            return "element";
    }
}

export function describeAction(action: RecipeAction): string {
    switch (action.type) {
        case "goto":
            return `Navigate to ${action.url}`;
        case "click":
            return `Click ${describeLocator(action.locator)}`;
        case "fill":
            return `Fill ${describeLocator(action.locator)} with "${action.value}"`;
        case "selectOption":
            return `Select "${action.value}" in ${describeLocator(action.locator)}`;
        case "press":
            return `Press "${action.value}" on ${describeLocator(action.locator)}`;
        case "waitForURL":
            return `Wait for URL ${action.url}`;
        case "waitForLoadState":
            return `Wait for load state "${action.state}"`;
        case "waitForVisible":
            return `Wait for ${describeLocator(action.locator)}`;
        case "download":
            return `Download via ${describeLocator(action.locator)}`;
        default:
            return "Unknown step";
    }
}

type Props = {
    actions: RecipeAction[];
    onChange: (actions: RecipeAction[]) => void;
    /** Highlighted when a run failed on this step. */
    failedIndex?: number | null;
};

/**
 * Edit the normalised action list.
 *
 * Steps are structured data throughout — this component only reorders, deletes,
 * and toggles a click into a download. It never accepts free-form code.
 */
export function ActionList({ actions, onChange, failedIndex = null }: Props) {
    function move(index: number, delta: number) {
        const next = [...actions];
        const target = index + delta;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    }

    function remove(index: number) {
        onChange(actions.filter((_, i) => i !== index));
    }

    function toggleDownload(index: number) {
        const next = [...actions];
        const action = next[index];
        // The export button is a click in Codegen output, but a recipe needs it
        // marked as the download step so the runner arms the download listener.
        next[index] = { ...action, type: action.type === "download" ? "click" : "download" };
        onChange(next);
    }

    if (actions.length === 0) {
        return (
            <p className="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
                No steps yet. Paste Playwright Codegen output below to build them.
            </p>
        );
    }

    return (
        <ol className="flex flex-col gap-1.5">
            {actions.map((action, index) => {
                const failed = failedIndex === index;
                const canBeDownload = action.type === "click" || action.type === "download";

                return (
                    <li
                        key={index}
                        className={`flex items-center gap-2 rounded-md border px-2 py-1.5 text-sm ${
                            failed ? "border-destructive bg-destructive/5" : ""
                        }`}
                    >
                        <span className="w-6 shrink-0 text-center text-xs text-muted-foreground tabular-nums">
                            {index + 1}
                        </span>
                        {action.type === "download" && (
                            <Badge variant="info" className="shrink-0">
                                download
                            </Badge>
                        )}
                        {action.opensPopup && (
                            <Badge variant="warning" className="shrink-0">
                                opens window
                            </Badge>
                        )}
                        <span className="min-w-0 flex-1 truncate">{describeAction(action)}</span>
                        <div className="flex shrink-0 items-center gap-0.5">
                            {canBeDownload && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="h-7 px-2 text-xs"
                                    onClick={() => toggleDownload(index)}
                                    title="Mark this step as the one that triggers the CSV download"
                                >
                                    {action.type === "download" ? "Make click" : "Make download"}
                                </Button>
                            )}
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                onClick={() => move(index, -1)}
                                disabled={index === 0}
                                aria-label={`Move step ${index + 1} up`}
                            >
                                <ArrowUp className="size-3.5" />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                onClick={() => move(index, 1)}
                                disabled={index === actions.length - 1}
                                aria-label={`Move step ${index + 1} down`}
                            >
                                <ArrowDown className="size-3.5" />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                onClick={() => remove(index)}
                                aria-label={`Remove step ${index + 1}`}
                            >
                                <Trash2 className="size-3.5" />
                            </Button>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

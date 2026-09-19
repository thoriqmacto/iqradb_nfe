"use client";

import { useState } from "react";
import { ArrowDown, ArrowUp, Clock, SlidersHorizontal, Trash2 } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { LocatorStrategy, RecipeAction, RecipeLocator } from "@/lib/scrapper/types";

const SELECT_CLASS =
    "border-input dark:bg-input/30 h-8 rounded-md border bg-transparent px-2 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]";

/** Strategies and the locator field each one reads. Mirrors LOCATOR_STRATEGIES. */
const STRATEGY_FIELD: Record<LocatorStrategy, keyof RecipeLocator> = {
    role: "role",
    label: "label",
    text: "text",
    placeholder: "placeholder",
    testId: "testId",
    css: "css",
};

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
 * Steps are structured data throughout — reorder, delete, toggle a click into a
 * download, retarget a locator, and adjust a per-step timeout. Every edit goes
 * through a typed field; this component never accepts free-form code, and the
 * API re-validates each action against the closed vocabulary regardless.
 */
export function ActionList({ actions, onChange, failedIndex = null }: Props) {
    const [editing, setEditing] = useState<number | null>(null);

    function update(index: number, action: RecipeAction) {
        const next = [...actions];
        next[index] = action;
        onChange(next);
    }

    /**
     * Insert an explicit wait for the same element before the step.
     *
     * A legacy ASP.NET postback often has not repainted by the time the next
     * click runs. Waiting on the element itself is the fix — it costs nothing
     * when the page is already settled.
     */
    function insertWaitBefore(index: number) {
        const action = actions[index];
        if (!action.locator) return;
        const wait: RecipeAction = { type: "waitForVisible", locator: action.locator };
        onChange([...actions.slice(0, index), wait, ...actions.slice(index)]);
        setEditing(null);
    }

    function move(index: number, delta: number) {
        const next = [...actions];
        const target = index + delta;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
        setEditing(null);
    }

    function remove(index: number) {
        onChange(actions.filter((_, i) => i !== index));
        setEditing(null);
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
                        className={`flex flex-col gap-2 rounded-md border px-2 py-1.5 text-sm ${
                            failed ? "border-destructive bg-destructive/5" : ""
                        }`}
                    >
                      <div className="flex items-center gap-2">
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
                        {action.timeoutMs !== undefined && (
                            <Badge variant="outline" className="shrink-0 gap-1">
                                <Clock className="size-3" />
                                {action.timeoutMs}ms
                            </Badge>
                        )}
                        <span className="min-w-0 flex-1 truncate">{describeAction(action)}</span>
                        <div className="flex shrink-0 items-center gap-0.5">
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className={`size-7 ${editing === index ? "bg-accent" : ""}`}
                                onClick={() => setEditing(editing === index ? null : index)}
                                aria-label={`Edit step ${index + 1}`}
                                aria-expanded={editing === index}
                                title="Retarget this step or give it more time"
                            >
                                <SlidersHorizontal className="size-3.5" />
                            </Button>
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
                      </div>

                      {editing === index && (
                          <StepEditor
                              action={action}
                              onChange={(next) => update(index, next)}
                              onInsertWait={() => insertWaitBefore(index)}
                          />
                      )}
                    </li>
                );
            })}
        </ol>
    );
}

/**
 * The three knobs that fix a broken step without re-recording it.
 *
 * `waitForVisible` before the step covers a postback that has not repainted, a
 * longer timeout covers a slow one, and retargeting the locator covers markup
 * that renders differently than it did during recording. The strategy list is
 * the same closed set the API accepts.
 */
function StepEditor({
    action,
    onChange,
    onInsertWait,
}: {
    action: RecipeAction;
    onChange: (action: RecipeAction) => void;
    onInsertWait: () => void;
}) {
    const locator = action.locator;

    function setLocator(patch: Partial<RecipeLocator>) {
        if (!locator) return;
        onChange({ ...action, locator: { ...locator, ...patch } });
    }

    function setStrategy(strategy: LocatorStrategy) {
        if (!locator) return;
        // Carry the current value across so switching role -> css keeps the text
        // to edit rather than blanking the field.
        const current = String(locator[STRATEGY_FIELD[locator.strategy]] ?? "");
        const next: RecipeLocator = { strategy, [STRATEGY_FIELD[strategy]]: current };
        if (locator.hasText) next.hasText = locator.hasText;
        if (locator.nth !== undefined) next.nth = locator.nth;
        if (strategy === "role" && locator.name) next.name = locator.name;
        onChange({ ...action, locator: next });
    }

    function setStepTimeout(raw: string) {
        const value = raw.trim();
        if (value === "") {
            // Clearing the field means "use the recipe-wide default", which is
            // the absence of the key rather than a zero.
            const cleared = { ...action };
            delete cleared.timeoutMs;
            onChange(cleared);
            return;
        }
        const parsed = Number.parseInt(value, 10);
        if (Number.isNaN(parsed)) return;
        onChange({ ...action, timeoutMs: Math.min(120000, Math.max(100, parsed)) });
    }

    return (
        <div className="flex flex-col gap-2 rounded-md border bg-muted/40 p-2">
            {locator ? (
                <>
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="flex flex-col gap-1">
                            <Label className="text-xs text-muted-foreground">Find by</Label>
                            <select
                                value={locator.strategy}
                                onChange={(event) =>
                                    setStrategy(event.target.value as LocatorStrategy)
                                }
                                className={SELECT_CLASS}
                                aria-label="Locator strategy"
                            >
                                {(Object.keys(STRATEGY_FIELD) as LocatorStrategy[]).map((value) => (
                                    <option key={value} value={value}>
                                        {value}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex min-w-48 flex-1 flex-col gap-1">
                            <Label className="text-xs text-muted-foreground">
                                {locator.strategy === "css" ? "CSS selector" : "Value"}
                            </Label>
                            <Input
                                value={String(locator[STRATEGY_FIELD[locator.strategy]] ?? "")}
                                onChange={(event) =>
                                    setLocator({ [STRATEGY_FIELD[locator.strategy]]: event.target.value })
                                }
                                className="h-8 font-mono text-xs"
                                placeholder={
                                    locator.strategy === "css"
                                        ? 'a[id*="Redline"]'
                                        : "grid"
                                }
                            />
                        </div>
                        {locator.strategy === "role" && (
                            <div className="flex min-w-32 flex-col gap-1">
                                <Label className="text-xs text-muted-foreground">
                                    Accessible name
                                </Label>
                                <Input
                                    value={locator.name ?? ""}
                                    onChange={(event) =>
                                        setLocator({ name: event.target.value || undefined })
                                    }
                                    className="h-8 font-mono text-xs"
                                />
                            </div>
                        )}
                    </div>

                    <div className="flex flex-wrap items-end gap-2">
                        <div className="flex min-w-48 flex-1 flex-col gap-1">
                            <Label className="text-xs text-muted-foreground">
                                Narrow to rows containing
                            </Label>
                            <Input
                                value={locator.hasText ?? ""}
                                onChange={(event) =>
                                    setLocator({ hasText: event.target.value || undefined })
                                }
                                className="h-8 font-mono text-xs"
                                placeholder="report name"
                            />
                        </div>
                        <div className="flex w-20 flex-col gap-1">
                            <Label className="text-xs text-muted-foreground">nth</Label>
                            <Input
                                type="number"
                                min={0}
                                value={locator.nth ?? ""}
                                onChange={(event) =>
                                    setLocator({
                                        nth:
                                            event.target.value === ""
                                                ? undefined
                                                : Number.parseInt(event.target.value, 10),
                                    })
                                }
                                className="h-8 text-xs"
                            />
                        </div>
                        <div className="flex w-28 flex-col gap-1">
                            <Label className="text-xs text-muted-foreground">Timeout (ms)</Label>
                            <Input
                                type="number"
                                min={100}
                                max={120000}
                                step={500}
                                value={action.timeoutMs ?? ""}
                                onChange={(event) => setStepTimeout(event.target.value)}
                                className="h-8 text-xs"
                                placeholder="default"
                            />
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-8 text-xs"
                            onClick={onInsertWait}
                            title="Insert a wait for this same element before the step"
                        >
                            Add wait before
                        </Button>
                    </div>
                </>
            ) : (
                <div className="flex w-28 flex-col gap-1">
                    <Label className="text-xs text-muted-foreground">Timeout (ms)</Label>
                    <Input
                        type="number"
                        min={100}
                        max={120000}
                        step={500}
                        value={action.timeoutMs ?? ""}
                        onChange={(event) => setStepTimeout(event.target.value)}
                        className="h-8 text-xs"
                        placeholder="default"
                    />
                </div>
            )}
        </div>
    );
}

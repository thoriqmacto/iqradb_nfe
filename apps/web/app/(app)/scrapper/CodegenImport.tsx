"use client";

import { useState } from "react";
import { toast } from "sonner";
import { AlertTriangle, Wand2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { parseCodegen, readError } from "@/lib/scrapper/api";
import type { RecipeAction, UnsupportedCodegenLine } from "@/lib/scrapper/types";

import { describeAction } from "./ActionList";

const PLACEHOLDER = `await page.getByRole('link', { name: 'Reports' }).click();
await page.getByText('Loop Index').click();
await page.getByLabel('Train').selectOption('Train-8');
await page.getByRole('button', { name: 'Export' }).click();`;

type Props = {
    onApply: (actions: RecipeAction[], source: string) => void;
};

/**
 * Paste Playwright Codegen output, get structured steps back.
 *
 * The paste is sent to the API and converted by string matching. It is never
 * executed — not here, not on the server. Statements that cannot be converted
 * with confidence are listed as unsupported rather than guessed at.
 */
export function CodegenImport({ onApply }: Props) {
    const [source, setSource] = useState("");
    const [parsing, setParsing] = useState(false);
    const [actions, setActions] = useState<RecipeAction[] | null>(null);
    const [unsupported, setUnsupported] = useState<UnsupportedCodegenLine[]>([]);

    async function onParse() {
        if (source.trim() === "") {
            toast.error("Paste some Playwright Codegen output first.");
            return;
        }

        setParsing(true);
        try {
            const result = await parseCodegen(source);
            setActions(result.actions);
            setUnsupported(result.unsupported);

            if (result.summary.converted === 0) {
                toast.warning("Nothing in that paste could be converted into a safe step.");
            } else {
                toast.success(
                    `Converted ${result.summary.converted} step(s)` +
                        (result.summary.unsupported > 0
                            ? `, ${result.summary.unsupported} need manual setup.`
                            : "."),
                );
            }
        } catch (err) {
            toast.error(readError(err, "Could not parse that Codegen output."));
        } finally {
            setParsing(false);
        }
    }

    return (
        <div className="flex flex-col gap-3 rounded-md border bg-muted/20 p-3">
            <div>
                <h4 className="text-sm font-medium">Paste Codegen</h4>
                <p className="text-xs text-muted-foreground">
                    Paste statements from <code className="font-mono">npx playwright codegen</code>.
                    They are converted into structured steps — the code itself is never run.
                </p>
            </div>

            <Textarea
                value={source}
                onChange={(event) => setSource(event.target.value)}
                placeholder={PLACEHOLDER}
                rows={6}
                className="font-mono text-xs"
                spellCheck={false}
            />

            <div className="flex flex-wrap gap-2">
                <Button type="button" size="sm" onClick={onParse} disabled={parsing}>
                    <Wand2 className="size-4" />
                    {parsing ? "Converting…" : "Convert to steps"}
                </Button>
                {actions !== null && actions.length > 0 && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                            onApply(actions, source);
                            toast.success("Steps applied. Review and edit them before saving.");
                        }}
                    >
                        Apply {actions.length} step(s)
                    </Button>
                )}
            </div>

            {actions !== null && (
                <div className="grid gap-3 md:grid-cols-2">
                    <div>
                        <h5 className="mb-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Codegen input
                        </h5>
                        <pre className="max-h-64 overflow-auto rounded border bg-background p-2 font-mono text-xs">
                            {source}
                        </pre>
                    </div>
                    <div>
                        <h5 className="mb-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Normalized actions
                        </h5>
                        {actions.length === 0 ? (
                            <p className="rounded border border-dashed p-2 text-xs text-muted-foreground">
                                Nothing convertible.
                            </p>
                        ) : (
                            <ol className="max-h-64 space-y-1 overflow-auto rounded border bg-background p-2 text-xs">
                                {actions.map((action, index) => (
                                    <li key={index} className="flex gap-2">
                                        <span className="text-muted-foreground tabular-nums">
                                            {index + 1}.
                                        </span>
                                        <span>{describeAction(action)}</span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </div>
                </div>
            )}

            {unsupported.length > 0 && (
                <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs dark:border-amber-900 dark:bg-amber-950">
                    <p className="mb-1.5 flex items-center gap-1.5 font-medium text-amber-900 dark:text-amber-200">
                        <AlertTriangle className="size-3.5" />
                        {unsupported.length} statement(s) could not be converted safely
                    </p>
                    <ul className="flex flex-col gap-1.5">
                        {unsupported.map((item) => (
                            <li key={`${item.line}-${item.source}`}>
                                <code className="font-mono text-amber-900 dark:text-amber-200">
                                    line {item.line}: {item.source}
                                </code>
                                <p className="text-amber-800 dark:text-amber-300">{item.reason}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

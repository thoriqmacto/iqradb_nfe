"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { createRecipe, readError, updateRecipe } from "@/lib/scrapper/api";
import type { Recipe, RecipeAction } from "@/lib/scrapper/types";

import { ActionList } from "./ActionList";
import { CodegenImport } from "./CodegenImport";

type Props = {
    recipe: Recipe | null;
    onSaved: () => void;
    onCancel: () => void;
};

const SCDB_BASE = "https://chiyodanfe.ceccms.com";

export function RecipeForm({ recipe, onSaved, onCancel }: Props) {
    const [name, setName] = useState(recipe?.name ?? "");
    const [description, setDescription] = useState(recipe?.description ?? "");
    const [startUrl, setStartUrl] = useState(recipe?.start_url ?? `${SCDB_BASE}/`);
    const [datasetKey, setDatasetKey] = useState(recipe?.dataset_key ?? "");
    const [filenamePattern, setFilenamePattern] = useState(recipe?.expected_filename_pattern ?? "");
    const [fileType, setFileType] = useState(recipe?.expected_file_type ?? "csv");
    const [actions, setActions] = useState<RecipeAction[]>(recipe?.actions ?? []);
    const [codegenSource, setCodegenSource] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    async function onSubmit(event: React.FormEvent) {
        event.preventDefault();

        if (actions.length === 0) {
            toast.error("Add at least one step before saving.");
            return;
        }

        setSaving(true);
        try {
            const payload = {
                name,
                description: description || null,
                start_url: startUrl,
                dataset_key: datasetKey,
                expected_file_type: fileType,
                expected_filename_pattern: filenamePattern || null,
                actions,
                ...(codegenSource ? { codegen_source: codegenSource } : {}),
            };

            if (recipe) {
                await updateRecipe(recipe.id, payload);
                toast.success("Recipe updated.");
            } else {
                await createRecipe(payload);
                toast.success("Recipe created.");
            }

            onSaved();
        } catch (err) {
            toast.error(readError(err, "Could not save the recipe."));
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-4 rounded-md border p-4">
            <h3 className="text-sm font-semibold">{recipe ? "Edit recipe" : "New recipe"}</h3>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="recipe-name">Name</Label>
                    <Input
                        id="recipe-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Loop Index report"
                        required
                        maxLength={120}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="recipe-dataset">Dataset key</Label>
                    <Input
                        id="recipe-dataset"
                        value={datasetKey}
                        onChange={(event) => setDatasetKey(event.target.value)}
                        placeholder="loop_index"
                        pattern="[a-z][a-z0-9_]*"
                        required
                        maxLength={64}
                    />
                    <p className="text-xs text-muted-foreground">
                        Lowercase identifier the import adapter is keyed on. Until a target model
                        exists for it, imports stop at staging.
                    </p>
                </div>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="recipe-description">Description</Label>
                <Input
                    id="recipe-description"
                    value={description}
                    onChange={(event) => setDescription(event.target.value)}
                    placeholder="Loop index export for the current train"
                    maxLength={255}
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="recipe-url">SCDB start URL</Label>
                <Input
                    id="recipe-url"
                    value={startUrl}
                    onChange={(event) => setStartUrl(event.target.value)}
                    placeholder={`${SCDB_BASE}/Reports.aspx`}
                    className="font-mono text-xs"
                    required
                />
                <p className="text-xs text-muted-foreground">
                    Must be on the allowed SCDB host. Any other host is rejected.
                </p>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="recipe-filetype">Export format</Label>
                <select
                    id="recipe-filetype"
                    value={fileType}
                    onChange={(event) => setFileType(event.target.value)}
                    className="border-input dark:bg-input/30 h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                >
                    <option value="csv">CSV — downloaded, parsed and staged</option>
                    <option value="xlsx">XLSX — downloaded and stored only</option>
                </select>
                {fileType !== "csv" && (
                    <p className="text-xs text-muted-foreground">
                        Importing is only implemented for CSV. An XLSX recipe can still
                        &ldquo;Run &amp; download&rdquo; — the file is captured and checksummed —
                        but it cannot be parsed or staged. Pick CSV in the SCDB export wizard if
                        that option is offered.
                    </p>
                )}
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="recipe-pattern">Expected filename pattern (optional)</Label>
                <Input
                    id="recipe-pattern"
                    value={filenamePattern}
                    onChange={(event) => setFilenamePattern(event.target.value)}
                    placeholder="LoopIndex*.csv"
                    className="font-mono text-xs"
                    maxLength={255}
                />
            </div>

            <div className="flex flex-col gap-2">
                <Label>Steps</Label>
                <ActionList actions={actions} onChange={setActions} />
            </div>

            <CodegenImport
                onApply={(parsed, source) => {
                    setActions(parsed);
                    setCodegenSource(source);
                }}
            />

            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={saving}>
                    {saving ? "Saving…" : recipe ? "Save changes" : "Create recipe"}
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

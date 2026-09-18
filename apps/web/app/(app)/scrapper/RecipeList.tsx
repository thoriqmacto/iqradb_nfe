"use client";

import { useState } from "react";
import useSWR from "swr";
import { toast } from "sonner";
import { Download, FlaskConical, Pencil, Play, Plus, Trash2 } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import {
    RECIPES_KEY,
    deleteRecipe,
    fetchRecipes,
    readError,
    startRun,
} from "@/lib/scrapper/api";
import type { Recipe, RunMode } from "@/lib/scrapper/types";

import { RecipeForm } from "./RecipeForm";
import { formatTimestamp } from "./status";

type Props = {
    onRunStarted: (runId: string) => void;
};

export function RecipeList({ onRunStarted }: Props) {
    const { data: recipes, isLoading, mutate } = useSWR<Recipe[]>(RECIPES_KEY, fetchRecipes);
    const [editing, setEditing] = useState<Recipe | null>(null);
    const [creating, setCreating] = useState(false);
    const [startingId, setStartingId] = useState<string | null>(null);

    async function onStart(recipe: Recipe, mode: RunMode) {
        setStartingId(recipe.id);
        try {
            // Returns 202 immediately — the browser work happens on the VPS
            // queue, and the run history polls for progress.
            const run = await startRun(recipe.id, mode);
            onRunStarted(run.id);
            toast.success("Run queued.");
        } catch (err) {
            toast.error(readError(err, "Could not start the run."));
        } finally {
            setStartingId(null);
        }
    }

    async function onDelete(recipe: Recipe) {
        try {
            await deleteRecipe(recipe.id);
            await mutate();
            toast.success("Recipe deleted.");
        } catch (err) {
            toast.error(readError(err, "Could not delete the recipe."));
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Report Recipes</CardTitle>
                <CardDescription>
                    Each recipe describes how to reach one SCDB report and export it. Steps are
                    structured data, not code.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {(creating || editing) && (
                    <RecipeForm
                        recipe={editing}
                        onSaved={() => {
                            setCreating(false);
                            setEditing(null);
                            void mutate();
                        }}
                        onCancel={() => {
                            setCreating(false);
                            setEditing(null);
                        }}
                    />
                )}

                {!creating && !editing && (
                    <Button size="sm" className="self-start" onClick={() => setCreating(true)}>
                        <Plus className="size-4" />
                        New recipe
                    </Button>
                )}

                {isLoading && <p className="text-sm text-muted-foreground">Loading recipes…</p>}

                {!isLoading && (recipes?.length ?? 0) === 0 && (
                    <p className="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
                        No recipes yet. Create one to start automating an SCDB report.
                    </p>
                )}

                <div className="flex flex-col gap-3">
                    {recipes?.map((recipe) => (
                        <div key={recipe.id} className="flex flex-col gap-2 rounded-md border p-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <h3 className="font-medium">{recipe.name}</h3>
                                        <Badge variant="outline" className="font-mono">
                                            {recipe.dataset_key}
                                        </Badge>
                                        {!recipe.enabled && (
                                            <Badge variant="secondary">Disabled</Badge>
                                        )}
                                    </div>
                                    {recipe.description && (
                                        <p className="text-sm text-muted-foreground">
                                            {recipe.description}
                                        </p>
                                    )}
                                    <p className="mt-1 font-mono text-xs text-muted-foreground">
                                        {recipe.start_url}
                                    </p>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setEditing(recipe)}
                                        aria-label={`Edit ${recipe.name}`}
                                    >
                                        <Pencil className="size-3.5" />
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => onDelete(recipe)}
                                        aria-label={`Delete ${recipe.name}`}
                                    >
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </div>
                            </div>

                            <dl className="flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted-foreground">
                                <div className="flex gap-1.5">
                                    <dt>Steps:</dt>
                                    <dd>{recipe.actions.length}</dd>
                                </div>
                                <div className="flex gap-1.5">
                                    <dt>Last success:</dt>
                                    <dd>{formatTimestamp(recipe.last_success_run_at)}</dd>
                                </div>
                                <div className="flex gap-1.5">
                                    <dt>Last failure:</dt>
                                    <dd>{formatTimestamp(recipe.last_failed_run_at)}</dd>
                                </div>
                            </dl>

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={startingId === recipe.id || !recipe.enabled}
                                    onClick={() => onStart(recipe, "test_navigation")}
                                >
                                    <FlaskConical className="size-3.5" />
                                    Test navigation
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={startingId === recipe.id || !recipe.enabled}
                                    onClick={() => onStart(recipe, "download")}
                                >
                                    <Download className="size-3.5" />
                                    Run &amp; download
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={startingId === recipe.id || !recipe.enabled}
                                    onClick={() => onStart(recipe, "import")}
                                >
                                    <Play className="size-3.5" />
                                    Run, download &amp; import
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

<?php

declare(strict_types=1);

namespace Kami\Cocktail\Services;

use Throwable;
use Kami\Cocktail\Models\Tag;
use Illuminate\Log\LogManager;
use Kami\Cocktail\Models\User;
use Kami\Cocktail\Models\Image;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kami\Cocktail\Models\Cocktail;
use Illuminate\Database\DatabaseManager;
use Kami\Cocktail\Models\CocktailFavorite;
use Kami\Cocktail\Models\CocktailIngredient;
use Kami\Cocktail\Exceptions\CocktailException;
use Kami\Cocktail\DataObjects\Cocktail\Ingredient;
use Kami\Cocktail\Models\CocktailIngredientSubstitute;
use Kami\Cocktail\DataObjects\Cocktail\Cocktail as CocktailDTO;

class CocktailService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LogManager $log,
    ) {
    }

    /**
     * Create a new cocktail
     *
     * @param CocktailDTO $cocktailDTO
     * @return \Kami\Cocktail\Models\Cocktail
     */
    public function createCocktail(CocktailDTO $cocktailDTO): Cocktail
    {
        $this->db->beginTransaction();

        try {
            $cocktail = new Cocktail();
            $cocktail->name = $cocktailDTO->name;
            $cocktail->instructions = $cocktailDTO->instructions;
            $cocktail->description = $cocktailDTO->description;
            $cocktail->garnish = $cocktailDTO->garnish;
            $cocktail->source = $cocktailDTO->source;
            $cocktail->user_id = $cocktailDTO->userId;
            $cocktail->glass_id = $cocktailDTO->glassId;
            $cocktail->cocktail_method_id = $cocktailDTO->methodId;
            $cocktail->save();

            foreach ($cocktailDTO->ingredients as $ingredient) {
                if (!($ingredient instanceof Ingredient)) {
                    $this->log->warning('[COCKTAIL_SERVICE] Ingredient in ingredients array is of wrong type!');
                    continue;
                }

                $cIngredient = new CocktailIngredient();
                $cIngredient->ingredient_id = $ingredient->id;
                $cIngredient->amount = $ingredient->amount;
                $cIngredient->units = $ingredient->units;
                $cIngredient->optional = $ingredient->optional;
                $cIngredient->sort = $ingredient->sort;

                $cocktail->ingredients()->save($cIngredient);

                // Substitutes
                foreach ($ingredient->substitutes as $subId) {
                    $substitute = new CocktailIngredientSubstitute();
                    $substitute->ingredient_id = $subId;
                    $cIngredient->substitutes()->save($substitute);
                }
            }

            $dbTags = [];
            foreach ($cocktailDTO->tags as $tagName) {
                $tag = Tag::firstOrNew([
                    'name' => trim($tagName),
                ]);
                $tag->save();
                $dbTags[] = $tag->id;
            }

            $cocktail->tags()->attach($dbTags);
        } catch (Throwable $e) {
            $this->log->error('[COCKTAIL_SERVICE] ' . $e->getMessage());
            $this->db->rollBack();

            throw new CocktailException('Error occured while creating a cocktail!', 0, $e);
        }

        $this->db->commit();

        if (count($cocktailDTO->images) > 0) {
            try {
                $imageModels = Image::findOrFail($cocktailDTO->images);
                $cocktail->attachImages($imageModels);
            } catch (Throwable $e) {
                $this->log->error('[COCKTAIL_SERVICE] Image attach error. ' . $e->getMessage());

                throw new CocktailException('Error occured while attaching images to cocktail!', 0, $e);
            }
        }

        $this->log->info('[COCKTAIL_SERVICE] Cocktail "' . $cocktailDTO->name . '" created with id: ' . $cocktail->id);

        // Refresh model for response
        $cocktail->refresh();
        // Upsert scout index
        $cocktail->save();

        return $cocktail;
    }

    /**
     * Update cocktail by id
     *
     * @param int $id
     * @param string $name
     * @param string $instructions
     * @param array<Ingredient> $ingredients
     * @param int $userId
     * @param string|null $description
     * @param string|null $garnish
     * @param string|null $cocktailSource
     * @param array<int> $images
     * @param array<string> $tags
     * @param int|null $glassId
     * @param int|null $cocktailMethodId
     * @return \Kami\Cocktail\Models\Cocktail
     */
    public function updateCocktail(
        int $id,
        string $name,
        string $instructions,
        array $ingredients,
        int $userId,
        ?string $description = null,
        ?string $garnish = null,
        ?string $cocktailSource = null,
        array $images = [],
        array $tags = [],
        ?int $glassId = null,
        ?int $cocktailMethodId = null
    ): Cocktail {
        $this->db->beginTransaction();

        try {
            $cocktail = Cocktail::findOrFail($id);
            $cocktail->name = $name;
            $cocktail->instructions = $instructions;
            $cocktail->description = $description;
            $cocktail->garnish = $garnish;
            $cocktail->source = $cocktailSource;
            if ($cocktail->user_id !== 1) {
                $cocktail->user_id = $userId;
            }
            $cocktail->glass_id = $glassId;
            $cocktail->cocktail_method_id = $cocktailMethodId;
            $cocktail->save();

            // TODO: Implement upsert and delete
            $cocktail->ingredients()->delete();
            foreach ($ingredients as $ingredient) {
                if (!($ingredient instanceof Ingredient)) {
                    $this->log->warning('[COCKTAIL_SERVICE] Ingredient in ingredients array is of wrong type!');
                    continue;
                }

                $cIngredient = new CocktailIngredient();
                $cIngredient->ingredient_id = $ingredient->id;
                $cIngredient->amount = $ingredient->amount;
                $cIngredient->units = $ingredient->units;
                $cIngredient->optional = $ingredient->optional;
                $cIngredient->sort = $ingredient->sort;

                $cocktail->ingredients()->save($cIngredient);

                // Substitutes
                $cIngredient->substitutes()->delete();
                foreach ($ingredient->substitutes as $subId) {
                    $substitute = new CocktailIngredientSubstitute();
                    $substitute->ingredient_id = $subId;
                    $cIngredient->substitutes()->save($substitute);
                }
            }

            $dbTags = [];
            foreach ($tags as $tagName) {
                $tag = Tag::firstOrNew([
                    'name' => trim($tagName),
                ]);
                $tag->save();
                $dbTags[] = $tag->id;
            }

            $cocktail->tags()->sync($dbTags);
        } catch (Throwable $e) {
            $this->log->error('[COCKTAIL_SERVICE] ' . $e->getMessage());
            $this->db->rollBack();

            throw new CocktailException('Error occured while updating a cocktail with id "' . $id . '"!', 0, $e);
        }

        $this->db->commit();

        if (count($images) > 0) {
            // $cocktail->deleteImages();
            try {
                $imageModels = Image::findOrFail($images);
                $cocktail->attachImages($imageModels);
            } catch (Throwable $e) {
                $this->log->error('[COCKTAIL_SERVICE] Image attach error. ' . $e->getMessage());

                throw new CocktailException('Error occured while attaching images to cocktail!', 0, $e);
            }
        }

        $this->log->info('[COCKTAIL_SERVICE] Updated cocktail with id: ' . $cocktail->id);

        // Refresh model for response
        $cocktail->refresh();
        // Upsert scout index
        $cocktail->save();

        return $cocktail;
    }

    /**
     * Return all cocktails that user can create with
     * ingredients in his shelf
     *
     * @param int $userId
     * @return \Illuminate\Support\Collection<string, mixed>
     */
    public function getCocktailsByUserIngredients(int $userId, ?int $limit = null): Collection
    {
        $userIngredientIds = $this->db->table('user_ingredients')->select('ingredient_id')->where('user_id', $userId)->pluck('ingredient_id');

        $query = $this->db->table('cocktails AS c')
            ->select('c.id')
            ->join('cocktail_ingredients AS ci', 'ci.cocktail_id', '=', 'c.id')
            ->leftJoin('cocktail_ingredient_substitutes AS cis', 'cis.cocktail_ingredient_id', '=', 'ci.id')
            ->where('optional', false);

        if (config('bar-assistant.parent_ingredient_as_substitute')) {
            $query->join('ingredients AS i', function ($join) {
                $join->on('i.id', '=', 'ci.ingredient_id')->orOn('i.id', '=', 'i.parent_ingredient_id');
            })
            ->where(function ($query) use ($userIngredientIds) {
                $query->whereNull('i.parent_ingredient_id')
                    ->whereIn('i.id', $userIngredientIds);
            })
            ->orWhere(function ($query) use ($userIngredientIds) {
                $query->whereNotNull('i.parent_ingredient_id')
                    ->where(function ($sub) use ($userIngredientIds) {
                        $sub->whereIn('i.id', $userIngredientIds)->orWhereIn('i.parent_ingredient_id', $userIngredientIds);
                    });
            });
        } else {
            $query->join('ingredients AS i', 'i.id', '=', 'ci.ingredient_id')
            ->whereIn('i.id', $userIngredientIds);
        }

        $query->orWhereIn('cis.ingredient_id', $userIngredientIds)
        ->groupBy('c.id')
        ->havingRaw('COUNT(*) >= (SELECT COUNT(*) FROM cocktail_ingredients WHERE cocktail_id = c.id AND optional = false)');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->pluck('id');
    }

    /**
     * Match cocktails ingredients to users shelf ingredients
     * Does not include substitutes
     *
     * @param int $cocktailId
     * @param int $userId
     * @return array<int>
     */
    public function matchAvailableShelfIngredients(int $cocktailId, int $userId): array
    {
        return $this->db->table('ingredients AS i')
            ->select('i.id')
            ->leftJoin('user_ingredients AS ui', 'ui.ingredient_id', '=', 'i.id')
            ->where('ui.user_id', $userId)
            ->whereRaw('i.id IN (SELECT ingredient_id FROM cocktail_ingredients ci WHERE ci.cocktail_id = ?)', [$cocktailId])
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get cocktail average ratings
     *
     * @return array<int, float>
     */
    public function getCocktailAvgRatings(): array
    {
        return $this->db->table('ratings')
            ->select('rateable_id AS cocktail_id', DB::raw('AVG(rating) AS avg_rating'))
            ->where('rateable_type', Cocktail::class)
            ->groupBy('rateable_id')
            ->get()
            ->keyBy('cocktail_id')
            ->map(fn ($r) => $r->avg_rating)
            ->toArray();
    }

    public function getCocktailUserRatings(int $userId): array
    {
        return $this->db->table('ratings')
            ->select('rateable_id AS cocktail_id', 'rating')
            ->where('rateable_type', Cocktail::class)
            ->where('user_id', $userId)
            ->groupBy('rateable_id')
            ->get()
            ->keyBy('cocktail_id')
            ->map(fn ($r) => $r->rating)
            ->toArray();
    }

    /**
     * Toggle user favorite cocktail
     *
     * @param \Kami\Cocktail\Models\User $user
     * @param int $cocktailId
     * @return bool
     */
    public function toggleFavorite(User $user, int $cocktailId): bool
    {
        $cocktail = Cocktail::find($cocktailId);

        if (!$cocktail) {
            return false;
        }

        $existing = CocktailFavorite::where('cocktail_id', $cocktailId)->where('user_id', $user->id)->first();
        if ($existing) {
            $existing->delete();

            return false;
        }

        $cocktailFavorite = new CocktailFavorite();
        $cocktailFavorite->cocktail_id = $cocktail->id;

        $user->favorites()->save($cocktailFavorite);

        return true;
    }
}

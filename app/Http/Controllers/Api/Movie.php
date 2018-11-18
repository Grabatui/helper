<?php

namespace App\Http\Controllers\Api;

use App\Entities\Movie as MovieEntity;
use App\Http\Controllers\Controller;
use App\Http\Resources\Movie as MovieResource;
use App\Http\Resources\MovieCollection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Siqwell\Kinopoisk\Client;
use Siqwell\Kinopoisk\Models\Film;

class Movie extends Controller
{
    public function queue(Request $request)
    {
        $query = $request->get('query');

        $movies = MovieEntity::whereWatched(false)->orderBy('sort');

        if (Str::length($query) > 0) {
            $movies->where('name', 'like', '%' . $query . '%');
        }

        return MovieCollection::make($movies->get());
    }

    public function watched(Request $request)
    {
        $query = $request->get('query');

        $movies = MovieEntity::whereWatched(true)->orderByDesc('watched_at');

        if (Str::length($query) > 0) {
            $movies->where('name', 'like', '%' . $query . '%');
        }

        return MovieCollection::make($movies->get());
    }

    public function search(Request $request)
    {
        $query = $request->get('query');
        $limit = $request->get('limit', 10);

        if (empty($query)) {
            return MovieCollection::make();
        }

        /** @var Client $parser */
        $parser = app('kinopoisk.parser');
        $result = $parser->getSearchApi()->searchFilm($query);

        if ($result instanceof Collection) {
            $result = $result->slice(0, $limit);
        }

        if ($result->count() > 0) {
            $result = $this->convertSearchToMovieCollection($result);
        }

        return MovieCollection::make($result);
    }

    public function detail(MovieEntity $movie)
    {
        return MovieResource::make($movie);
    }

    public function watch(MovieEntity $movie, Request $request)
    {
        $movie->watched = true;
        $movie->watched_at = Carbon::now();

        if ($request->has('rating')) {
            $movie->rating = $request->post('rating');
        }

        if ($request->has('opinion')) {
            $movie->opinion = $request->post('opinion');
        }

        return new JsonResponse(($movie->save()) ? 'success' : 'error');
    }

    public function add(Request $request)
    {
        $data = array_only($request->toArray(), ['name', 'original_name', 'image', 'kp_id', 'year']);

        $movie = new MovieEntity($data);

        $movie->watched = false;
        $movie->sort = MovieEntity::orderByDesc('sort')->first(['sort'])->sort + MovieEntity::SORT_STEP;

        return new JsonResponse(($movie->save()) ? $movie->id : null);
    }

    /**
     * @param Collection|Film[] $movies
     * @return Collection
     */
    private function convertSearchToMovieCollection(Collection $movies)
    {
        $ids = $movies->reduce(function ($cherry, Film $film) {
            $cherry[] = $film->getAttribute('id');

            return $cherry;
        });

        $exists = (new MovieEntity)->whereIn('kp_id', $ids)->get()->keyBy('kp_id');

        return $movies->transform(function (Film $film) use ($exists) {
            $kpId = $film->getAttribute('id');

            if ($exists->has($kpId)) {
                return $exists->get($kpId);
            }

            return MovieEntity::createFromKP($film);
        });
    }
}

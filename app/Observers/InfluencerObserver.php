<?php

namespace App\Observers;

use App\Models\Influencer;
use App\Models\Collaboration;
use App\Models\CollaborationRequest;

class InfluencerObserver
{
    /**
     * Handle the Influencer "created" event.
     */
    public function created(Influencer $influencer): void {}

    /**
     * Handle the Influencer "updated" event.
     */
    public function updated(Influencer $influencer): void
    {
        // Get the categories of the new influencer
        $categories = json_decode($influencer->category);

        // Fetch collaborations that match the influencer's categories and the collab_value
        $collaborations = Collaboration::where(function ($query) use ($categories) {
            foreach ($categories as $categoryId) {
                $query->orWhereRaw('JSON_CONTAINS(category, ?)', [json_encode($categoryId)]);
            }
        })->where('amount', '>=', $influencer->collab_value)
            ->get();

        // Loop through each collaboration and create a CollaborationRequest
        foreach ($collaborations as $collaboration) {
            // Check if a collaboration request already exists for this influencer and collaboration
            $existingRequest = CollaborationRequest::where('collaboration_id', $collaboration->id)
                ->where('influencer_id', $influencer->id)
                ->first();

            // If no request exists, create a new one
            if (!$existingRequest) {
                CollaborationRequest::create([
                    'collaboration_id' => $collaboration->id,
                    'influencer_id' => $influencer->id,
                    'status' => 1, // pending
                ]);
            }
        }
    }

    /**
     * Handle the Influencer "deleted" event.
     */
    public function deleted(Influencer $influencer): void
    {
        //
    }

    /**
     * Handle the Influencer "restored" event.
     */
    public function restored(Influencer $influencer): void
    {
        //
    }

    /**
     * Handle the Influencer "force deleted" event.
     */
    public function forceDeleted(Influencer $influencer): void
    {
        //
    }
}

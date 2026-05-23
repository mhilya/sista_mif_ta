<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassificationResult extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['confidence_score' => 'float'];
    public function scopeInternal($q) { return $q->where('source_type', 'internal_mif'); }
    public function scopeNeedsReview($q) { return $q->where('status', 'needs_review'); }
}


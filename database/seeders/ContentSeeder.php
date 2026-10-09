<?php

namespace Database\Seeders;

use App\Enums\ContentType;
use App\Models\ContentItem;
use Illuminate\Database\Seeder;

/**
 * The content catalogue: 40 items over 6 topics, articles and question sets.
 * A fixed list, so the titles are the same on every machine.
 */
class ContentSeeder extends Seeder
{
    /**
     * Topic => list of [title, type, estimated minutes].
     *
     * @var array<string, list<array{string, ContentType, int}>>
     */
    private const CATALOGUE = [
        'Cardiology' => [
            ['Acute Coronary Syndromes: An Overview', ContentType::Article, 20],
            ['ECG Interpretation Basics', ContentType::Article, 25],
            ['Atrial Fibrillation Management', ContentType::Article, 15],
            ['Valvular Heart Disease', ContentType::Article, 20],
            ['Heart Failure Question Set', ContentType::QuestionSet, 30],
            ['ECG Rhythm Strips Question Set', ContentType::QuestionSet, 25],
            ['Hypertension Pharmacology Question Set', ContentType::QuestionSet, 20],
        ],
        'Pulmonology' => [
            ['Asthma in Adults', ContentType::Article, 15],
            ['COPD Exacerbations', ContentType::Article, 15],
            ['Interpreting Pulmonary Function Tests', ContentType::Article, 20],
            ['Pulmonary Embolism Workup', ContentType::Article, 15],
            ['Chest X-Ray Question Set', ContentType::QuestionSet, 30],
            ['Arterial Blood Gas Question Set', ContentType::QuestionSet, 25],
            ['Pneumonia Management Question Set', ContentType::QuestionSet, 20],
        ],
        'Nephrology' => [
            ['Acute Kidney Injury', ContentType::Article, 20],
            ['Chronic Kidney Disease Staging', ContentType::Article, 15],
            ['Electrolyte Disorders: Sodium and Potassium', ContentType::Article, 25],
            ['Glomerulonephritis Overview', ContentType::Article, 20],
            ['Acid-Base Disorders Question Set', ContentType::QuestionSet, 35],
            ['Fluid Management Question Set', ContentType::QuestionSet, 20],
            ['Renal Pharmacology Question Set', ContentType::QuestionSet, 25],
        ],
        'Neurology' => [
            ['Stroke Recognition and Acute Management', ContentType::Article, 20],
            ['Approach to Seizures', ContentType::Article, 15],
            ['Headache Red Flags', ContentType::Article, 10],
            ['Multiple Sclerosis Overview', ContentType::Article, 15],
            ['Neurological Examination Question Set', ContentType::QuestionSet, 25],
            ['Neuroanatomy Localisation Question Set', ContentType::QuestionSet, 35],
            ["Parkinson's Disease Question Set", ContentType::QuestionSet, 20],
        ],
        'Endocrinology' => [
            ['Type 1 Diabetes Management', ContentType::Article, 20],
            ['Thyroid Function Tests Explained', ContentType::Article, 15],
            ['Adrenal Insufficiency', ContentType::Article, 15],
            ['Diabetic Ketoacidosis Question Set', ContentType::QuestionSet, 30],
            ['Insulin Regimens Question Set', ContentType::QuestionSet, 20],
            ['Calcium and Bone Metabolism Question Set', ContentType::QuestionSet, 25],
        ],
        'Pediatrics' => [
            ['Developmental Milestones', ContentType::Article, 15],
            ['Fever in Infants', ContentType::Article, 15],
            ['Childhood Immunisation Schedule', ContentType::Article, 10],
            ['Pediatric Asthma Question Set', ContentType::QuestionSet, 25],
            ['Neonatal Jaundice Question Set', ContentType::QuestionSet, 20],
            ['Pediatric Fluid and Dosing Question Set', ContentType::QuestionSet, 30],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::CATALOGUE as $topic => $items) {
            foreach ($items as [$title, $type, $minutes]) {
                ContentItem::query()->create([
                    'title' => $title,
                    'topic' => $topic,
                    'type' => $type,
                    'estimated_minutes' => $minutes,
                ]);
            }
        }
    }
}

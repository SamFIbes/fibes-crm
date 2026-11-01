<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Currency;
use App\Models\District;
use App\Models\ExchangeRate;
use App\Models\Governorate;
use App\Models\Holiday;
use App\Models\TicketCategory;
use App\Services\Calls\CallDesk;
use App\Services\Inventory\StockLedger;
use App\Services\Jobs\FieldWork;
use App\Services\Tickets\EscalationLadder;
use App\Support\Geography\Lebanon;
use Illuminate\Database\Seeder;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Lebanon::tree() as $governorate) {
            $gov = Governorate::query()->updateOrCreate(
                ['name_en' => $governorate['name_en']],
                ['name_ar' => $governorate['name_ar']],
            );

            foreach ($governorate['districts'] as $district) {
                $dist = District::query()->updateOrCreate(
                    ['governorate_id' => $gov->id, 'name_en' => $district['name_en']],
                    ['name_ar' => $district['name_ar']],
                );

                foreach ($district['areas'] as $area) {
                    Area::query()->updateOrCreate(
                        ['district_id' => $dist->id, 'name_en' => $area['name_en']],
                        ['name_ar' => $area['name_ar']],
                    );
                }
            }
        }

        Currency::query()->updateOrCreate(['code' => 'USD'], [
            'name_en' => 'US dollar',
            'name_ar' => 'دولار أمريكي',
            'decimals' => 2,
            'symbol' => '$',
        ]);
        Currency::query()->updateOrCreate(['code' => 'LBP'], [
            'name_en' => 'Lebanese pound',
            'name_ar' => 'ليرة لبنانية',
            'decimals' => 0,
            'symbol' => 'ل.ل.',
        ]);

        if (! ExchangeRate::query()->exists()) {
            ExchangeRate::query()->create([
                'effective_on' => '2026-01-01',
                'usd_lbp' => '89500.0000',
                'confirmed' => false,
                'note' => 'Placeholder. Finance must record the rate it will use.',
                'set_by' => null,
            ]);
        }

        $holidays = [
            ['2026-01-01', 'New Year', 'رأس السنة'],
            ['2026-02-09', 'Saint Maron', 'عيد مار مارون'],
            ['2026-03-25', 'Annunciation', 'عيد البشارة'],
            ['2026-04-03', 'Western Good Friday', 'الجمعة العظيمة الغربية'],
            ['2026-04-05', 'Western Easter', 'الفصح الغربي'],
            ['2026-04-10', 'Eastern Good Friday', 'الجمعة العظيمة الشرقية'],
            ['2026-04-12', 'Eastern Easter', 'الفصح الشرقي'],
            ['2026-05-01', 'Labour Day', 'عيد العمال'],
            ['2026-08-15', 'Assumption', 'عيد الانتقال'],
            ['2026-11-22', 'Independence Day', 'عيد الاستقلال'],
            ['2026-12-25', 'Christmas', 'عيد الميلاد'],
        ];

        foreach ($holidays as [$date, $en, $ar]) {
            Holiday::query()->updateOrCreate(
                ['observed_on' => $date],
                ['name_en' => $en, 'name_ar' => $ar],
            );
        }

        $categories = [
            ['no_internet', 'No internet', 'لا إنترنت'],
            ['slow_speed', 'Slow speed', 'سرعة بطيئة'],
            ['intermittent', 'Intermittent', 'تقطع'],
            ['fiber_cut', 'Physical damage / fiber cut', 'ضرر أو قطع ألياف'],
            ['router_ont', 'Router / ONT issue', 'مشكلة في الموجّه أو جهاز الألياف'],
            ['relocation', 'Relocation', 'نقل'],
            ['billing', 'Billing question', 'سؤال عن الفوترة'],
            ['installation', 'New installation', 'تركيب جديد'],
            ['other', 'Other', 'أخرى'],
        ];

        foreach ($categories as $index => [$key, $en, $ar]) {
            TicketCategory::query()->updateOrCreate(
                ['key' => $key],
                ['name_en' => $en, 'name_ar' => $ar, 'is_active' => true, 'sort' => $index],
            );
        }

        app(EscalationLadder::class)->seed();
        app(CallDesk::class)->seedCodes();
        app(FieldWork::class)->seed();
        app(StockLedger::class)->seed();
    }
}

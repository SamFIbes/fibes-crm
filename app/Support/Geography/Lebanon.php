<?php

namespace App\Support\Geography;

/**
 * Official Lebanese governorates and districts, plus a starter set of towns
 * and neighborhoods. More areas are added by an administrator.
 */
class Lebanon
{
    /**
     * @return list<array{name_en: string, name_ar: string, districts: list<array{name_en: string, name_ar: string, areas: list<array{name_en: string, name_ar: string}>}>}>
     */
    public static function tree(): array
    {
        return [
            self::gov('Beirut', 'بيروت', [
                self::dist('Beirut', 'بيروت', [
                    ['Achrafieh', 'الأشرفية'],
                    ['Hamra', 'الحمرا'],
                    ['Verdun', 'فردان'],
                    ['Ras Beirut', 'رأس بيروت'],
                    ['Mazraa', 'المزرعة'],
                    ['Ain el Mreisseh', 'عين المريسة'],
                ]),
            ]),
            self::gov('Mount Lebanon', 'جبل لبنان', [
                self::dist('Baabda', 'بعبدا', [
                    ['Baabda', 'بعبدا'],
                    ['Hadath', 'الحدث'],
                    ['Hazmieh', 'الحازمية'],
                    ['Furn el Chebbak', 'فرن الشباك'],
                ]),
                self::dist('Aley', 'عاليه', [
                    ['Aley', 'عاليه'],
                    ['Bhamdoun', 'بحمدون'],
                ]),
                self::dist('Matn', 'المتن', [
                    ['Sin el Fil', 'سن الفيل'],
                    ['Bourj Hammoud', 'برج حمود'],
                    ['Antelias', 'أنطلياس'],
                    ['Jal el Dib', 'جل الديب'],
                    ['Broummana', 'برمانا'],
                ]),
                self::dist('Chouf', 'الشوف', [
                    ['Damour', 'الدامور'],
                    ['Deir el Qamar', 'دير القمر'],
                ]),
            ]),
            self::gov('Keserwan-Jbeil', 'كسروان جبيل', [
                self::dist('Keserwan', 'كسروان', [
                    ['Jounieh', 'جونيه'],
                    ['Zouk Mikael', 'ذوق مكايل'],
                    ['Kaslik', 'الكسليك'],
                ]),
                self::dist('Jbeil', 'جبيل', [
                    ['Byblos', 'جبيل'],
                    ['Amchit', 'عمشيت'],
                ]),
            ]),
            self::gov('North', 'الشمال', [
                self::dist('Tripoli', 'طرابلس', [
                    ['Tripoli', 'طرابلس'],
                    ['El Mina', 'الميناء'],
                ]),
                self::dist('Zgharta', 'زغرتا', [
                    ['Zgharta', 'زغرتا'],
                ]),
                self::dist('Koura', 'الكورة', [
                    ['Amioun', 'أميون'],
                ]),
                self::dist('Batroun', 'البترون', [
                    ['Batroun', 'البترون'],
                ]),
                self::dist('Bsharri', 'بشري', [
                    ['Bsharri', 'بشري'],
                ]),
                self::dist('Minieh-Danniyeh', 'المنية الضنية', [
                    ['Minieh', 'المنية'],
                ]),
            ]),
            self::gov('Akkar', 'عكار', [
                self::dist('Akkar', 'عكار', [
                    ['Halba', 'حلبا'],
                ]),
            ]),
            self::gov('Beqaa', 'البقاع', [
                self::dist('Zahle', 'زحلة', [
                    ['Zahle', 'زحلة'],
                    ['Chtaura', 'شتورا'],
                ]),
                self::dist('West Beqaa', 'البقاع الغربي', [
                    ['Joub Jannine', 'جب جنين'],
                ]),
                self::dist('Rashaya', 'راشيا', [
                    ['Rashaya', 'راشيا'],
                ]),
            ]),
            self::gov('Baalbek-Hermel', 'بعلبك الهرمل', [
                self::dist('Baalbek', 'بعلبك', [
                    ['Baalbek', 'بعلبك'],
                ]),
                self::dist('Hermel', 'الهرمل', [
                    ['Hermel', 'الهرمل'],
                ]),
            ]),
            self::gov('South', 'الجنوب', [
                self::dist('Sidon', 'صيدا', [
                    ['Sidon', 'صيدا'],
                ]),
                self::dist('Tyre', 'صور', [
                    ['Tyre', 'صور'],
                ]),
                self::dist('Jezzine', 'جزين', [
                    ['Jezzine', 'جزين'],
                ]),
            ]),
            self::gov('Nabatieh', 'النبطية', [
                self::dist('Nabatieh', 'النبطية', [
                    ['Nabatieh', 'النبطية'],
                ]),
                self::dist('Marjeyoun', 'مرجعيون', [
                    ['Marjeyoun', 'مرجعيون'],
                ]),
                self::dist('Hasbaya', 'حاصبيا', [
                    ['Hasbaya', 'حاصبيا'],
                ]),
                self::dist('Bint Jbeil', 'بنت جبيل', [
                    ['Bint Jbeil', 'بنت جبيل'],
                ]),
            ]),
        ];
    }

    /**
     * @param  list<array{name_en: string, name_ar: string, areas: list<array{name_en: string, name_ar: string}>}>  $districts
     * @return array{name_en: string, name_ar: string, districts: list<array{name_en: string, name_ar: string, areas: list<array{name_en: string, name_ar: string}>}>}
     */
    private static function gov(string $en, string $ar, array $districts): array
    {
        return ['name_en' => $en, 'name_ar' => $ar, 'districts' => $districts];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $areas
     * @return array{name_en: string, name_ar: string, areas: list<array{name_en: string, name_ar: string}>}
     */
    private static function dist(string $en, string $ar, array $areas): array
    {
        return [
            'name_en' => $en,
            'name_ar' => $ar,
            'areas' => array_map(fn (array $area) => ['name_en' => $area[0], 'name_ar' => $area[1]], $areas),
        ];
    }
}

<?php

/*
 * Indonesian translation of Laravel's default validation language lines.
 * Public-beta readiness — with no `lang/` directory and `fallback_locale`
 * `en`, every validation rule this codebase does not hand-write its own
 * message for (`App\Domain\Booking\Actions\SaveBookingDraftStep`'s
 * customer/deceased-field messages are the exception, already Indonesian)
 * fell through to Laravel's English default — an English "The email field
 * is required." on an otherwise fully-Indonesian bereavement-booking form.
 *
 * A faithful, key-for-key translation of `vendor/laravel/framework/src/
 * Illuminate/Translation/lang/en/validation.php` — every `:placeholder`
 * token (`:attribute`, `:min`, `:max`, `:other`, `:values`, ...) is
 * Laravel's own replacement syntax and is preserved verbatim; only the
 * surrounding prose is translated. `attributes` stays empty, matching the
 * English stub — no attribute-name overrides exist anywhere in this
 * codebase to translate.
 */

return [

    'accepted' => ':attribute wajib disetujui.',
    'accepted_if' => ':attribute wajib disetujui apabila :other bernilai :value.',
    'active_url' => ':attribute wajib berupa URL yang valid.',
    'after' => ':attribute wajib berupa tanggal setelah :date.',
    'after_or_equal' => ':attribute wajib berupa tanggal setelah atau sama dengan :date.',
    'alpha' => ':attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => ':attribute hanya boleh berisi huruf dan angka.',
    'any_of' => ':attribute tidak valid.',
    'array' => ':attribute wajib berupa larik (array).',
    'ascii' => ':attribute hanya boleh berisi karakter alfanumerik dan simbol satu-byte.',
    'base64' => ':attribute wajib berupa string Base64 yang valid.',
    'before' => ':attribute wajib berupa tanggal sebelum :date.',
    'before_or_equal' => ':attribute wajib berupa tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => ':attribute wajib memiliki antara :min dan :max item.',
        'file' => ':attribute wajib berukuran antara :min dan :max kilobyte.',
        'numeric' => ':attribute wajib bernilai antara :min dan :max.',
        'string' => ':attribute wajib berisi antara :min dan :max karakter.',
    ],
    'boolean' => ':attribute wajib bernilai benar atau salah.',
    'can' => ':attribute berisi nilai yang tidak diizinkan.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'contains' => ':attribute belum berisi nilai yang wajib ada.',
    'current_password' => 'Kata sandi salah.',
    'date' => ':attribute wajib berupa tanggal yang valid.',
    'date_equals' => ':attribute wajib berupa tanggal yang sama dengan :date.',
    'date_format' => ':attribute wajib sesuai format :format.',
    'decimal' => ':attribute wajib memiliki :decimal angka desimal.',
    'declined' => ':attribute wajib ditolak.',
    'declined_if' => ':attribute wajib ditolak apabila :other bernilai :value.',
    'different' => ':attribute dan :other wajib berbeda.',
    'digits' => ':attribute wajib :digits digit.',
    'digits_between' => ':attribute wajib antara :min dan :max digit.',
    'dimensions' => 'Dimensi gambar :attribute tidak valid.',
    'distinct' => ':attribute memiliki nilai yang duplikat.',
    'doesnt_contain' => ':attribute tidak boleh berisi salah satu dari: :values.',
    'doesnt_end_with' => ':attribute tidak boleh diakhiri dengan salah satu dari: :values.',
    'doesnt_start_with' => ':attribute tidak boleh diawali dengan salah satu dari: :values.',
    'email' => ':attribute wajib berupa alamat email yang valid.',
    'encoding' => ':attribute wajib menggunakan enkode :encoding.',
    'ends_with' => ':attribute wajib diakhiri dengan salah satu dari: :values.',
    'enum' => ':attribute yang dipilih tidak valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'extensions' => ':attribute wajib memiliki salah satu ekstensi berikut: :values.',
    'file' => ':attribute wajib berupa berkas.',
    'filled' => ':attribute wajib diisi.',
    'gt' => [
        'array' => ':attribute wajib memiliki lebih dari :value item.',
        'file' => ':attribute wajib lebih besar dari :value kilobyte.',
        'numeric' => ':attribute wajib lebih besar dari :value.',
        'string' => ':attribute wajib lebih dari :value karakter.',
    ],
    'gte' => [
        'array' => ':attribute wajib memiliki :value item atau lebih.',
        'file' => ':attribute wajib lebih besar atau sama dengan :value kilobyte.',
        'numeric' => ':attribute wajib lebih besar atau sama dengan :value.',
        'string' => ':attribute wajib lebih besar atau sama dengan :value karakter.',
    ],
    'hex_color' => ':attribute wajib berupa warna heksadesimal yang valid.',
    'image' => ':attribute wajib berupa gambar.',
    'in' => ':attribute yang dipilih tidak valid.',
    'in_array' => ':attribute wajib ada dalam :other.',
    'in_array_keys' => ':attribute wajib berisi minimal satu dari kunci berikut: :values.',
    'integer' => ':attribute wajib berupa bilangan bulat.',
    'ip' => ':attribute wajib berupa alamat IP yang valid.',
    'ipv4' => ':attribute wajib berupa alamat IPv4 yang valid.',
    'ipv6' => ':attribute wajib berupa alamat IPv6 yang valid.',
    'json' => ':attribute wajib berupa string JSON yang valid.',
    'list' => ':attribute wajib berupa daftar (list).',
    'lowercase' => ':attribute wajib huruf kecil.',
    'lt' => [
        'array' => ':attribute wajib memiliki kurang dari :value item.',
        'file' => ':attribute wajib kurang dari :value kilobyte.',
        'numeric' => ':attribute wajib kurang dari :value.',
        'string' => ':attribute wajib kurang dari :value karakter.',
    ],
    'lte' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :value item.',
        'file' => ':attribute wajib kurang dari atau sama dengan :value kilobyte.',
        'numeric' => ':attribute wajib kurang dari atau sama dengan :value.',
        'string' => ':attribute wajib kurang dari atau sama dengan :value karakter.',
    ],
    'mac_address' => ':attribute wajib berupa alamat MAC yang valid.',
    'max' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih besar dari :max kilobyte.',
        'numeric' => ':attribute tidak boleh lebih besar dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'max_digits' => ':attribute tidak boleh lebih dari :max digit.',
    'mimes' => ':attribute wajib berupa berkas bertipe: :values.',
    'mimetypes' => ':attribute wajib berupa berkas bertipe: :values.',
    'min' => [
        'array' => ':attribute wajib memiliki minimal :min item.',
        'file' => ':attribute wajib minimal :min kilobyte.',
        'numeric' => ':attribute wajib minimal :min.',
        'string' => ':attribute wajib minimal :min karakter.',
    ],
    'min_digits' => ':attribute wajib memiliki minimal :min digit.',
    'missing' => ':attribute wajib tidak ada.',
    'missing_if' => ':attribute wajib tidak ada apabila :other bernilai :value.',
    'missing_unless' => ':attribute wajib tidak ada kecuali :other bernilai :value.',
    'missing_with' => ':attribute wajib tidak ada apabila :values ada.',
    'missing_with_all' => ':attribute wajib tidak ada apabila :values semuanya ada.',
    'multiple_of' => ':attribute wajib merupakan kelipatan dari :value.',
    'not_in' => ':attribute yang dipilih tidak valid.',
    'not_regex' => 'Format :attribute tidak valid.',
    'numeric' => ':attribute wajib berupa angka.',
    'password' => [
        'letters' => ':attribute wajib mengandung minimal satu huruf.',
        'mixed' => ':attribute wajib mengandung minimal satu huruf besar dan satu huruf kecil.',
        'numbers' => ':attribute wajib mengandung minimal satu angka.',
        'symbols' => ':attribute wajib mengandung minimal satu simbol.',
        'uncompromised' => ':attribute yang dimasukkan pernah muncul dalam kebocoran data. Silakan pilih :attribute lain.',
    ],
    'present' => ':attribute wajib ada.',
    'present_if' => ':attribute wajib ada apabila :other bernilai :value.',
    'present_unless' => ':attribute wajib ada kecuali :other bernilai :value.',
    'present_with' => ':attribute wajib ada apabila :values ada.',
    'present_with_all' => ':attribute wajib ada apabila :values semuanya ada.',
    'prohibited' => ':attribute tidak diizinkan.',
    'prohibited_if' => ':attribute tidak diizinkan apabila :other bernilai :value.',
    'prohibited_if_accepted' => ':attribute tidak diizinkan apabila :other disetujui.',
    'prohibited_if_declined' => ':attribute tidak diizinkan apabila :other ditolak.',
    'prohibited_unless' => ':attribute tidak diizinkan kecuali :other ada dalam :values.',
    'prohibits' => ':attribute melarang :other untuk diisi.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'required_array_keys' => ':attribute wajib berisi entri untuk: :values.',
    'required_if' => ':attribute wajib diisi apabila :other bernilai :value.',
    'required_if_accepted' => ':attribute wajib diisi apabila :other disetujui.',
    'required_if_declined' => ':attribute wajib diisi apabila :other ditolak.',
    'required_unless' => ':attribute wajib diisi kecuali :other ada dalam :values.',
    'required_with' => ':attribute wajib diisi apabila :values ada.',
    'required_with_all' => ':attribute wajib diisi apabila :values semuanya ada.',
    'required_without' => ':attribute wajib diisi apabila :values tidak ada.',
    'required_without_all' => ':attribute wajib diisi apabila tidak satu pun dari :values ada.',
    'same' => ':attribute wajib sama dengan :other.',
    'size' => [
        'array' => ':attribute wajib berisi :size item.',
        'file' => ':attribute wajib berukuran :size kilobyte.',
        'numeric' => ':attribute wajib bernilai :size.',
        'string' => ':attribute wajib berisi :size karakter.',
    ],
    'starts_with' => ':attribute wajib diawali dengan salah satu dari: :values.',
    'string' => ':attribute wajib berupa teks.',
    'timezone' => ':attribute wajib berupa zona waktu yang valid.',
    'unique' => ':attribute sudah digunakan.',
    'uploaded' => ':attribute gagal diunggah.',
    'uppercase' => ':attribute wajib huruf besar.',
    'url' => ':attribute wajib berupa URL yang valid.',
    'ulid' => ':attribute wajib berupa ULID yang valid.',
    'uuid' => ':attribute wajib berupa UUID yang valid.',

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    'attributes' => [],

];

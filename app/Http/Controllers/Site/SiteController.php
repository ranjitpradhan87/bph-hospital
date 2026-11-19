<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\PublicSite;
use App\Support\Hospital;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Server-rendered public pages (good for search engines; no JavaScript required). */
class SiteController extends Controller
{
    public function home()
    {
        $featured = PublicSite::doctors(['featured' => true, 'page_size' => 4])['items'];
        $doctors = $featured ?: PublicSite::doctors(['page_size' => 4])['items'];

        return view('site.home', ['doctors' => $doctors, 'departments' => array_slice(PublicSite::departments(), 0, 8)]);
    }

    public function about()
    {
        return view('site.about');
    }

    public function services()
    {
        return view('site.services');
    }

    public function facilities()
    {
        return view('site.facilities');
    }

    public function departments()
    {
        return view('site.departments', ['departments' => PublicSite::departments()]);
    }

    public function department(string $slug)
    {
        $dept = PublicSite::department($slug) ?? abort(404);
        $doctors = PublicSite::doctors(['department' => $slug, 'page_size' => 50])['items'];

        return view('site.department', ['dept' => $dept, 'doctors' => $doctors]);
    }

    public function doctors(Request $request)
    {
        $f = $request->validate([
            'search' => 'nullable|string|max:100', 'department' => 'nullable|string|max:120', 'specialization' => 'nullable|string|max:120',
            'available_on' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1',
        ]);
        $result = PublicSite::doctors($f + ['page_size' => 12]);

        return view('site.doctors', [
            'result' => $result, 'filters' => $f, 'departments' => PublicSite::departments(), 'specializations' => PublicSite::specializations(),
            'noindex' => $f !== [],
        ]);
    }

    public function doctor(Request $request, int $id)
    {
        $doctor = PublicSite::doctor($id) ?? abort(404);
        $date = $request->query('date');
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $doctor['next_available_date'];
        }
        $slots = $date ? (PublicSite::doctorSlots($id, $date) ?? []) : [];

        return view('site.doctor', ['doctor' => $doctor, 'date' => $date, 'slots' => $slots, 'minDate' => Hospital::today()]);
    }

    public function book(Request $request)
    {
        return view('site.book', ['doctors' => PublicSite::doctors(['page_size' => 50])['items']]);
    }

    public function contact()
    {
        return view('site.contact');
    }

    public function sendContact(Request $request)
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => Phone::normalize((string) $request->input('phone'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'], 'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'], 'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:2000'], 'website' => ['nullable', 'string'],
        ], ['phone.regex' => 'Enter a valid 10-digit mobile number', 'message.min' => 'Please write at least 10 characters']);
        PublicSite::submitContact($data);

        return redirect()->to(url('/contact').'#form')->with('sent', true);
    }

    public function sitemap(): Response
    {
        $urls = [];
        foreach (['/', '/about', '/departments', '/doctors', '/services', '/facilities', '/contact'] as $p) {
            $urls[] = [url($p), null];
        }
        foreach (PublicSite::departments() as $d) {
            $urls[] = [url('/departments/'.$d['slug']), null];
        }
        foreach (DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.status', 'active')->whereNull('d.deleted_at')
            ->where('u.is_active', true)->whereNull('u.deleted_at')->get(['d.id', 'd.updated_at']) as $d) {
            $urls[] = [url('/doctors/'.$d->id), substr((string) $d->updated_at, 0, 10)];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as [$loc, $mod]) {
            $xml .= '<url><loc>'.e($loc).'</loc>'.($mod ? "<lastmod>$mod</lastmod>" : '').'</url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $txt = "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /admin\nDisallow: /portal\n\nSitemap: ".url('/sitemap.xml')."\n";

        return response($txt, 200, ['Content-Type' => 'text/plain']);
    }
}

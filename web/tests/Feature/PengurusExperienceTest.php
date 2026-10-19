<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Entry;
use App\Models\Meter;
use App\Models\User;
use App\Support\DatabaseGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PengurusExperienceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseGuard::check(true);
        config(['airbersih.mode' => 'operational']);
        $this->admin = User::create(['name' => 'Pengurus Uji', 'email' => Str::uuid().'@example.test', 'password' => 'password-for-testing-only', 'role' => 'superadmin', 'active' => true]);
    }

    public function test_empty_meter_page_explains_the_missing_connection_and_next_step(): void
    {
        $this->actingAs($this->admin)->get('/app/meters')->assertOk()->assertSee('Belum ada rumah yang terdaftar')
            ->assertSee('Daftarkan pelanggan dan rumah')->assertDontSee('wire:submit="saveBaseline"', false);
        $this->assertSame(0, Connection::count());
        $this->assertSame(0, Customer::count());
    }

    public function test_customer_registration_continues_into_connection_and_meter_selection(): void
    {
        $form = Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'customers'])
            ->set('form.name', 'Pelanggan Uji')->set('form.address', 'Alamat Rumah Uji')->call('saveCustomer')->assertHasNoErrors();
        $customer = Customer::sole();
        $form->assertSet('form.customer_id', (string) $customer->id)->assertSet('form.address', $customer->address)
            ->set('form.serial', 'METER-UJI')->call('saveConnection')->assertHasNoErrors();
        $connection = Connection::sole();
        $this->assertNull(Meter::sole()->last_value);
        Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'meters'])
            ->set('search', 'Pencarian tanpa hasil')
            ->assertViewHas('connectionOptions', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $connection->id)
            ->set('form.connection_id', (string) $connection->id)->call('selectConnection')
            ->assertSet('notice', '')->assertSee('Angka meter pertama rumah ini belum diisi');
        $this->assertSame(0, Entry::count());
    }

    public function test_guidance_and_visible_labels_avoid_programmer_terms_on_all_pages(): void
    {
        foreach (['dashboard', 'customers', 'meters', 'invoices', 'payments', 'finance', 'reports', 'imports', 'audit', 'settings', 'help'] as $page) {
            $response = $this->actingAs($this->admin)->get('/app/'.$page)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            $text = '';
            foreach ((new \DOMXPath($document))->query('//main//text()[not(ancestor::script)]') as $node) {
                $text .= ' '.$node->nodeValue;
            }
            $this->assertDoesNotMatchRegularExpression('/\b(?:baseline|Aiven|Supabase|snapshot|staging|reversal|checksum|database|cloud|superadmin|preview|invoice|workbook|workflow)\b/i', $text, 'Bahasa terlalu teknis di '.$page);
            $this->assertDoesNotMatchRegularExpression('/@(?:else|endcan|endif)\b/', $text, 'Kode templat terlihat pada halaman '.$page);
        }
    }

    public function test_officer_guide_does_not_offer_financial_or_administration_access(): void
    {
        $officer = User::create(['name' => 'Petugas Uji', 'email' => Str::uuid().'@example.test', 'password' => 'password-for-testing-only', 'role' => 'petugas', 'active' => true]);
        $this->actingAs($officer)->get('/app/help')->assertOk()->assertSee('Buka tugas pencatatan')
            ->assertDontSee('href="/app/finance"', false)->assertDontSee('href="/app/settings"', false);
        $this->actingAs($officer)->get('/app/meters')->assertOk()->assertSee('Belum ada rumah yang ditugaskan');
        $this->actingAs($officer)->get('/app/dashboard')->assertOk()->assertSee('minta pengurus memberi penugasan')
            ->assertDontSee('Mulai dari sini')->assertDontSee('Periksa tagihan yang belum dibayar');
    }

    public function test_opening_money_guide_only_prepares_the_form_without_inventing_money(): void
    {
        Account::create(['name' => 'Kas Uji', 'kind' => 'cash']);
        Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'finance'])->call('startOpeningBalance')
            ->assertSet('form.kind', 'opening')->assertSet('form.amount', '')->assertHasNoErrors();
        $this->assertSame(0, Entry::count());
        $this->assertNull(Account::sole()->opening_by);
    }

    public function test_missing_customer_fields_are_explained_in_indonesian(): void
    {
        Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'customers'])->call('saveCustomer')
            ->assertHasErrors(['name', 'address'])->assertSee('Isi nama terlebih dahulu')->assertSee('Isi alamat terlebih dahulu');
        $this->assertSame(0, Customer::count());
    }
}

# PDO Class Database

Modern PHP 8.2+ projeleri için geliştirilmiş; güvenli, hızlı ve nesne yönelimli (Active Record & Query Builder) hafif bir PDO veritabanı kütüphanesidir[cite: 1, 2].

## Özellikler

* **PHP 8.2+ Uyumlu:** Constructor Property Promotion, `readonly` sınıflar ve modern özellikler[cite: 1, 2].
* **Active Record Desteği:** `dbObject` tabanlı model yönetimi.
* **Gelişmiş Query Builder:** Zincirlenebilir (chainable) metod yapısı ile kolay sorgulama.
* **İlişkiler (Relationships):** `belongsTo`, `hasMany`, `hasOne` desteği.
* **Soft Deletes:** Silinen kayıtları veritabanından tamamen silmeden saklayabilme[cite: 1].
* **Collection Sınıfı:** Veri dizilerini dizi veya nesne olarak esnek yönetebilme[cite: 1].
* **Statement Caching:** Yüksek performans için hazırlanan sorguların önbelleklenmesi.

---

## Proje Klasör Yapısı

## text
pdo-class-database/
├── src/
│   ├── PdoDb.php          # PdoDb sınıfı
│   └── Model.php          # dbObject, Collection, Table, Column sınıfları
├── composer.json          # Paket yapılandırması
└── README.md              # Açıklama ve kullanım kılavuzu
Kurulum
Projeyi Composer ile dahil edebilir veya src/ klasörünü doğrudan projenize ekleyebilirsiniz:

## Bash
composer require indirbid/pdo-class-database

## Kullanım Kılavuzu
1. Veritabanı Bağlantısı (PdoDb)
Uygulamanızın başlangıç noktasında PdoDb sınıfını başlatın[cite: 2]:

```PHP
require 'vendor/autoload.php';

// Veritabanı bağlantısının kurulması
new PdoDb(
    host: 'localhost',
    username: 'root',
    password: 'secret_password',
    db: 'my_database',
    port: 3306,
    charset: 'utf8mb4'
);
2. Query Builder Kullanımı
Tablolar üzerinde doğrudan SQL yazmadan güvenli işlemler yapabilirsiniz[cite: 2]:

```PHP
// Veri Çekme (GET)
$users = PdoDb::getInstance()
    ->table('users')
    ->where('status', 1)
    ->orderBy('id', 'DESC')
    ->limit(10)
    ->get();

// Tekil Kayıt (First)
$user = PdoDb::getInstance()
    ->table('users')
    ->where('id', 5)
    ->first();

// Ekleme (Insert)
$newId = PdoDb::getInstance()
    ->table('users')
    ->insert([
        'name' => 'Ahmet Yılmaz',
        'email' => 'ahmet@example.com'
    ]);

// Güncelleme (Update)
PdoDb::getInstance()
    ->table('users')
    ->where('id', 5)
    ->update(['name' => 'Mehmet Yılmaz']);

// Silme (Delete)
PdoDb::getInstance()
    ->table('users')
    ->where('id', 5)
    ->delete();
3. Active Record (Model) Kullanımı
Modellerinizi dbObject sınıfından türeterek Active Record mimarisini kullanabilirsiniz[cite: 1]:

```PHP
#[Table(name: 'users')]
class User extends dbObject {
    protected bool $softDeletes = true; // Soft delete aktif
    protected array $fillable = ['name', 'email', 'status'];

    // İlişki Tanımı (Örn: Kullanıcının Yazıları)
    public function posts() {
        return $this->hasMany(Post::class, 'user_id');
    }
}
Model İşlevleri:
```PHP
// Tüm kayıtları getirme
$users = User::all();

// ID ile bulma (Bulamazsa hata fırlatma: findOrFail)
$user = User::find(1);

// Yeni kayıt oluşturma ve kaydetme
$user = new User();$user->name = 'Ayşe Kaya';
$user->email = 'ayse@example.com';
$user->save();

// Kayıt güncelleme
$user = User::find(1);$user->name = 'Yeni İsim';
$user->save();

// Kayıt silme (Soft Delete destekli)
$user = User::find(1);$user->delete();

// Sayfalama (Pagination)
$paginated = User::paginate(perPage: 15, page: 1);
Lisans
Bu proje MIT Lisansı ile lisanslanmıştır.

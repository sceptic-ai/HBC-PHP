<?php

interface infoproduk
{
    public function getinfoproduk();
}

abstract class produk
{
    protected  $judul,
        $penulis,
        $penerbit,
        $harga;

    protected $diskon = 0;

    public function getLabel()
    {
        return "$this->penulis,$this->penerbit";
    }

    public function __construct(
        $judul = "judul",
        $penulis = "penulis",
        $penerbit = "penerbit",
        $harga = 0
    ) {
        $this->judul = $judul;
        $this->penulis = $penulis;
        $this->penerbit = $penerbit;
        $this->harga = $harga;
    }

    public function setjudul($judul)
    {
        $this->judul = $judul;
    }

    public function getjudul()
    {
        return $this->judul;
    }

    public function setpenulis($penulis)
    {
        $this->penulis = $penulis;
    }

    public function getpenulis()
    {
        return $this->penulis;
    }

    public function setpenerbit($penerbit)
    {
        $this->penerbit = $penerbit;
    }

    public function getpenerbit()
    {
        return $this->penerbit;
    }

    public function setharga($harga)
    {
        $this->harga = $harga;
    }

    public function getharga()
    {
        return $this->harga - ($this->harga * $this->diskon / 100);
    }

    abstract public function getinfo();
}

class novel extends produk implements infoproduk
{
    public $jmlhalaman;

    public function __construct(
        $judul = "judul",
        $penulis = "penulis",
        $penerbit = "penerbit",
        $harga = 0,
        $jmlhalaman = 0
    ) {
        parent::__construct(
            $judul,
            $penulis,
            $penerbit,
            $harga
        );

        $this->jmlhalaman = $jmlhalaman;
    }


    public function setdiskon($diskon)
    {
        $this->diskon = $diskon;
    }


    public function getinfo()
    {
        $str = "{$this->judul} | {$this->getLabel()} (Rp. {$this->harga}) ";
        return $str;
    }

    public function getinfoproduk()
    {
        $str = "Novel : " . $this->getinfo() . " - {$this->jmlhalaman} Halaman ";

        return $str;
    }
}

class game extends produk implements infoproduk
{
    public $wktmain;

    public function __construct(
        $judul = "judul",
        $penulis = "penulis",
        $penerbit = "penerbit",
        $harga = 0,
        $wktmain = 0
    ) {
        parent::__construct(
            $judul,
            $penulis,
            $penerbit,
            $harga
        );

        $this->wktmain = $wktmain;
    }


    public function getinfo()
    {
        $str = "{$this->judul} | {$this->getLabel()} (Rp. {$this->harga}) ";
        return $str;
    }

    public function getinfoproduk()
    {
        $str = "Game : " . $this->getinfo() . " ~ {$this->wktmain} Jam ";

        return $str;
    }
}

class cetakinfoproduk
{
    public $daftarproduk = array();

    public function tambahproduk(produk $produk)
    {
        $this->daftarproduk[] = $produk;
    }

    public function cetak()
    {
        $str = "Daftar Produk: <br>";

        foreach ($this->daftarproduk as $p) {
            $str .= "- {$p->getinfoproduk()} <br>";
        }

        return $str;
    }
}

$pd1 = new novel(
    "Sherlock Holmes",
    "Sir Arthur Conan Doyle",
    "Gramedia Surabaya",
    80000,
    550
);

$pd2 = new game(
    "Death Stranding",
    "Hideo Kojima",
    "Sony Entertaiment",
    300000,
    80
);

$cetakproduk = new cetakinfoproduk();
$cetakproduk->tambahproduk($pd1);
$cetakproduk->tambahproduk($pd2);
echo $cetakproduk->cetak();

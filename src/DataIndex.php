<?php

declare(strict_types=1);

namespace OPA;

class DataIndex
{
    /** @var DataAsset[] */
    private array $assets = [];

    public function addAsset(DataAsset $asset): self
    {
        $this->assets[] = $asset;
        return $this;
    }

    /**
     * @return DataAsset[]
     */
    public function getAssets(): array
    {
        return $this->assets;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assets' => array_map(
                fn(DataAsset $a) => $a->toArray(),
                $this->assets,
            ),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $index = new self();

        foreach ($data['assets'] ?? [] as $assetData) {
            $index->addAsset(DataAsset::fromArray($assetData));
        }

        return $index;
    }
}

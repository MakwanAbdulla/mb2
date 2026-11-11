<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NavigationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'label'         => $this->label,
            'location'      => $this->location,
            'url'           => $this->resolvedUrl(),
            'route_name'    => $this->route_name,
            'icon'          => $this->icon,
            'order'         => $this->order,
            'requires_auth' => $this->requires_auth,
            'permission'    => $this->permission,
            'children'      => NavigationItemResource::collection($this->whenLoaded('children')),
        ];
    }
}

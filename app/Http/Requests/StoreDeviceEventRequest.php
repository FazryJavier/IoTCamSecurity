<?php

namespace App\Http\Requests;

use App\Enums\CameraSource;
use App\Enums\DeviceEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDeviceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DeviceEventType::class)->only(DeviceEventType::reportableByClient())],
            'source' => ['required', Rule::enum(CameraSource::class)],
            // source_changed: the source switched away from
            'previous_source' => ['nullable', 'required_if:type,source_changed', Rule::enum(CameraSource::class)],
            'snapshot' => ['nullable', 'file', 'mimes:jpg,jpeg', 'mimetypes:image/jpeg', 'max:1024'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // ESP32 motion is reported by the device itself through MQTT.
                $isMotion = in_array($this->input('type'), [DeviceEventType::MotionStarted->value, DeviceEventType::MotionStopped->value], true);
                if ($isMotion && $this->input('source') !== CameraSource::Laptop->value) {
                    $validator->errors()->add('source', 'Motion can only be reported by the dashboard for the laptop camera.');
                }
                if ($this->hasFile('snapshot') && $this->input('type') !== DeviceEventType::MotionStarted->value) {
                    $validator->errors()->add('snapshot', 'A snapshot can only be attached to a motion_started event.');
                }
            },
        ];
    }
}

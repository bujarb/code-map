export interface ButtonDto {
    label: string;
}

export function buttonDtoFromJson(json: unknown): ButtonDto {
    return json as ButtonDto;
}

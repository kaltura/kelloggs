import {DateTimePicker} from "material-ui-pickers";
import React from 'react';

export function Datepicker({onChange, time, name, label, onBlur, ...rest}) {

    return (
        <DateTimePicker
            name={name}
            label={label}
            onClose={onBlur}
            onBlur={onBlur}
            format={"YYYY-MM-DD hh:mm"}
            {...rest}
            onChange={(date) => onChange({target: {name, value: date ? date.format("YYYY-MM-DD hh:mm") : ""}})} />
    )
}
import React from "react";
import Grid from "@material-ui/core/Grid/Grid";
import Paper from "@material-ui/core/Paper/Paper";
import moment from "moment";
import FormControl from "@material-ui/core/FormControl/FormControl";
import InputLabel from "@material-ui/core/InputLabel/InputLabel";
import Select from "@material-ui/core/Select/Select";
import Input from "@material-ui/core/Input/Input";
import MenuItem from "@material-ui/core/MenuItem/MenuItem";
import ClearableTextField from "../ClearableTextField";
import { pick } from 'ramda';

const FLAVOR_ASSET = {
  name: "Flavor Asset",
  fields: ["entryIdIn", "idIn", "typeIn"]
};
const BATCH_JOB_SEP = {
  name: "Batch Job Sep",
  fields: ["entryIdIn", "objectIdIn", "jobTypeIn"]
};
const DEFAULT_FIELDS = ['type', 'table'];
const METADATA = { name: "Metadata", fields: ["objectIdIn", "objectTypeIn"] };
const FILE_SYNC = { name: "File Sync", fields: ["objectIdIn", "objectTypeIn"] };


const inputList = [
  { name: "entryIdIn", label: "Entry ID in" },
  { name: "objectIdIn", label: "Object ID in" },
  { name: "jobTypeIn", label: "Job Type in" },
  { name: "objectTypeIn", label: "Object Type in" },
  { name: "typeIn", label: "Type in" },
  { name: "idIn", label: "ID in" }
];
const tableMap = new Map([
  ["flavor_asset", FLAVOR_ASSET],
  ["batch_job_sep", BATCH_JOB_SEP],
  ["metadata", METADATA],
  ["file_sync", FILE_SYNC]
]);

const findInput = name =>
  inputList.filter(input => input.name === name)[0] || {};

export default class ObjectListParameters extends React.Component {
  state = {
    isFromTimeValid: true,
    isToTimeValid: true
  };

  filterParameters = parameters => {
    const { table } = parameters;
    let { fields } = tableMap.get(table);
    if (!fields) {
      return {};
    }
    fields = [...fields, ...DEFAULT_FIELDS];
    return pick(fields, parameters);
  };

  validate = () => {
    const isFromTimeValid = this._validateDate("fromTime", "isFromTimeValid");
    const isToTimeValid = this._validateDate("toTime", "isToTimeValid");
    return isFromTimeValid && isToTimeValid;
  };

  _validateDate = (propertyName, validStateName) => {
    const value = this.props[propertyName];
    const isValid = value && moment(value).isValid();
    this.setState({
      [validStateName]: isValid
    });

    return isValid;
  };

  _renderInputs = fields => {
    const { onClear, onChange } = this.props;
    return fields.map(field => {
      const { name, label } = findInput(field);
      return (
        <Grid item xs={4} key={name}>
          <ClearableTextField
            fullWidth
            name={name}
            label={label}
            value={this.props[name]}
            onClear={onClear}
            onChange={onChange}
            InputLabelProps={{
              shrink: true
            }}
          />
        </Grid>
      );
    });
  };

  render() {
    const { table, onChange, className: classNameProp } = this.props;
    const fields = tableMap.get(table);

    return (
      <Paper elevation={1} className={classNameProp}>
        <Grid container spacing={16}>
          <Grid item xs={4}>
            <FormControl fullWidth>
              <InputLabel>Table</InputLabel>
              <Select
                value={table}
                onChange={onChange}
                input={<Input name="table" id="type-input" />}
              >
                {Array.from(tableMap.keys()).map((fieldName, index) => {
                  return (
                    <MenuItem key={index} value={fieldName}>
                      {tableMap.get(fieldName).name}
                    </MenuItem>
                  );
                })}
              </Select>
            </FormControl>
          </Grid>
          {fields && this._renderInputs(fields.fields)}
        </Grid>
      </Paper>
    );
  }
}

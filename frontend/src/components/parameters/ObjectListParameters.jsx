import React from 'react';
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";
import moment from 'moment';
import FormControl from "@material-ui/core/FormControl/FormControl";
import InputLabel from "@material-ui/core/InputLabel/InputLabel";
import Select from "@material-ui/core/Select/Select";
import Input from "@material-ui/core/Input/Input";
import MenuItem from "@material-ui/core/MenuItem/MenuItem";
import ClearableTextField from '../ClearableTextField';

export default class ObjectListParameters extends React.Component {
  state = {
    isFromTimeValid: true,
    isToTimeValid: true
  }

  filterParameters = (parameters) => {

    const { table } = parameters;
    let fieldList = [];
    switch (table) {
      case 'flavor_asset':
        fieldList = ['type', 'table', 'entryIdIn', 'idIn', 'typeIn'];
        break;
      case 'batch_job_sep':
        fieldList = ['type', 'table', 'entryIdIn', 'objectIdIn', 'jobTypeIn'];
        break;
      case 'metadata':
        fieldList = ['type', 'table', 'objectIdIn', 'objectTypeIn'];
        break;
      case 'file_sync':
        fieldList = ['type', 'table', 'objectIdIn', 'objectTypeIn'];
        break;
    }

    return Object.keys(parameters).reduce((acc, parameterName) => {

      if (fieldList.indexOf(parameterName) !== -1) {
        acc[parameterName] = parameters[parameterName];
      }
      return acc;
    }, {});
  }

  validate = () => {
    const isFromTimeValid = this._validateDate('fromTime', 'isFromTimeValid');
    const isToTimeValid = this._validateDate('toTime', 'isToTimeValid');
    return isFromTimeValid && isToTimeValid;
  }

  _validateDate = (propertyName, validStateName) => {
    const value = this.props[propertyName];
    const isValid = (value && moment(value).isValid());
    this.setState({
      [validStateName]: isValid
    })

    return isValid;
  }

  render() {
    const { table, entryIdIn, typeIn, objectIdIn, jobTypeIn, objectTypeIn, idIn, onChange, onClear, className: classNameProp } = this.props;

    const objectIdInEnabled = ['batch_job_sep', 'metadata', 'file_sync'].indexOf(table) !== -1;
    const entryIdInEnabled = ['flavor_asset', 'batch_job_sep'].indexOf(table) !== -1;
    const objectTypeInEnabled = ['metadata', 'file_sync'].indexOf(table) !== -1;
    const jobTypeInEnabled = ['batch_job_sep'].indexOf(table) !== -1;
    const idInEnabled = ['flavor_asset'].indexOf(table) !== -1;
    const typeInEnabled = ['flavor_asset'].indexOf(table) !== -1;

    return (
      <Paper elevation={1} className={classNameProp}>
        <Grid container spacing={16} >
          <Grid item xs={4}>
            <FormControl
              fullWidth
            >
              <InputLabel>Table</InputLabel>
              <Select
                value={table}
                onChange={onChange}
                input={<Input name="table" id="type-input" />}
              >
                <MenuItem value={'flavor_asset'}>Flavor Asset</MenuItem>
                <MenuItem value={'batch_job_sep'}>Batch Job Sep</MenuItem>
                <MenuItem value={'metadata'}>Metadata</MenuItem>
                <MenuItem value={'file_sync'}>File Sync</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          { entryIdInEnabled &&
          <Grid item xs={4}>
            <ClearableTextField fullWidth
                       name="entryIdIn"
                       label="Entry ID in"
                       value={entryIdIn}
                       onClear={onClear}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>}
          { idInEnabled &&
          <Grid item xs={4}>
            <ClearableTextField fullWidth
                       name="idIn"
                       label="ID in"
                       onClear={onClear}
                       value={idIn}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>}
          { objectIdInEnabled &&
          <Grid item xs={4}>
            <ClearableTextField fullWidth
                       name="objectIdIn"
                       onClear={onClear}
                       label="Object ID in"
                       value={objectIdIn}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>}
          { jobTypeInEnabled &&
          <Grid item xs={4}>
            <ClearableTextField fullWidth
                       name="jobTypeIn"
                       onClear={onClear}
                       label="Job Type in"
                       value={jobTypeIn}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>}
          { typeInEnabled &&
          <Grid item xs={4}>
            <ClearableTextField fullWidth
                       name="typeIn"
                       onClear={onClear}
                       label="Type in"
                       value={typeIn}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>}
          { objectTypeInEnabled &&
          <Grid item xs={4}>
            <ClearableTextField fullWidth
                       onClear={onClear}
                       name="objectTypeIn"
                       label="Object Type in"
                       value={objectTypeIn}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>}
        </Grid>
      </Paper>
    )
  }
}

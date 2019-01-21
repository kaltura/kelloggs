import React from 'react';
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";
import moment from 'moment';
import Input from "@material-ui/core/Input/Input";
import FormControl from "@material-ui/core/FormControl/FormControl";
import MenuItem from "@material-ui/core/MenuItem/MenuItem";
import InputLabel from "@material-ui/core/InputLabel/InputLabel";
import Select from "@material-ui/core/Select/Select";

export default class DBLogsParameters extends React.Component {
  state = {
    isFromTimeValid: true,
    isToTimeValid: true
  }

  filterParameters = (parameters) => {
    return Object.keys(parameters).reduce((acc, parameterName) => {
      if (['type', 'fromTime', 'toTime', 'textFilter', 'table', 'objectId', 'logTypes'].indexOf(parameterName) !== -1) {
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
    const { textFilter, objectId, table, fromTime, toTime, onChange, className: classNameProp, onTextFilterChange, logTypes } = this.props;
    const { isFromTimeValid, isToTimeValid } = this.state;

    return (
      <Paper elevation={1} className={classNameProp}>
        <Grid container spacing={16} >
          <Grid item xs={4}>
            <TextField fullWidth
                       error={!isFromTimeValid}
                       name="fromTime"
                       label="From Time"
                       value={fromTime}
                       onChange={onChange}
                       helperText={!isFromTimeValid && "Date is missing or invalid"}
                       onBlur={() => this._validateDate('fromTime', 'isFromTimeValid')}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField fullWidth
                       error={!isToTimeValid}
                       name="toTime"
                       label="To Time"
                       value={toTime}
                       onChange={onChange}
                       helperText={!isToTimeValid && "Date is missing or invalid"}
                       onBlur={() => this._validateDate('toTime', 'isToTimeValid')}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField
              fullWidth
              label="Search Criteria"
              name={'textFilter'}
              value={textFilter.text}
              onChange={(e) => onTextFilterChange(e.target.value)}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField fullWidth
                       name="table"
                       label="Table"
                       value={table}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField
              fullWidth
              name="objectId"
              label="Object ID"
              value={objectId}
              onChange={onChange}
              InputLabelProps={{
                shrink: true,
              }}
            />
          </Grid>
          <Grid item xs={4}>
            <FormControl
              fullWidth
            >
              <InputLabel>Log Types</InputLabel>
            <Select
              value={logTypes}
              onChange={onChange}
              input={<Input name="logTypes" id="type-input" />}
            >
              <MenuItem value={'apiV3, ps2'}>apiV3, ps2</MenuItem>
              <MenuItem value={'apiV3'}>apiV3</MenuItem>
              <MenuItem value={'ps2'}>ps2</MenuItem>
            </Select>
            </FormControl>
          </Grid>
        </Grid>
      </Paper>
    )
  }
}

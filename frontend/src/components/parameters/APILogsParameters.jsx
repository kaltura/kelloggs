import React from 'react';
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";
import moment from 'moment-timezone';
import FormControl from "@material-ui/core/FormControl/FormControl";
import InputLabel from "@material-ui/core/InputLabel/InputLabel";
import Select from "@material-ui/core/Select/Select";
import Input from "@material-ui/core/Input/Input";
import MenuItem from "@material-ui/core/MenuItem/MenuItem";
import ClearableTextField from '../ClearableTextField';
import {pick} from "ramda";

const defaultParams = ['type', 'fromTime', 'toTime', 'textFilter', 'session', 'server', 'logTypes'];

export default class APILogsParameters extends React.Component {
  state = {
    isFromTimeValid: true,
    isToTimeValid: true
  };

  filterParameters = pick(defaultParams);


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

  componentDidMount() {
    const { onChange } = this.props;

    if (['apiV3, ps2','apiV3', 'ps2','apiV3Analytics','accessLog','vodAccessLog'].indexOf(this.props.logTypes) !== -1) {
      return;
    }

    onChange({ target : { name: 'logTypes', value: 'apiV3, ps2'}});
  }

  render() {
    const { textFilter, session, server, logTypes, fromTime, toTime, onChange, onClear, className: classNameProp, onTextFilterChange } = this.props;
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
            <ClearableTextField
              fullWidth
              label="Search Criteria"
              name={'textFilter'}
              onClear={() => onTextFilterChange("")}
              value={textFilter.text}
              onChange={(e) => onTextFilterChange(e.target.value)}
            />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField fullWidth
                                onClear={onClear}
                       name="server"
                       label="Server"
                       value={server}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField
              fullWidth
              onClear={onClear}
              name="session"
              label="Session"
              value={session}
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
                <MenuItem value={'apiV3Analytics'}>apiV3Analytics</MenuItem>
                <MenuItem value={'accessLog'}>accessLog</MenuItem>
                <MenuItem value={'vodAccessLog'}>vodAccessLog</MenuItem>
              </Select>
            </FormControl>
          </Grid>
        </Grid>
      </Paper>
    )
  }
}
